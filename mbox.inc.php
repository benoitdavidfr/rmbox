<?php
/*PhpDoc:
name: mbox.inc.php
title: mbox.inc.php - définition de classes pour gérer les messages d'un fichier Mbox
doc: |
  La classe Message gère un message ainsi que les fonctions d'extraction d'un message à partir d'un fichier Mbox.
  Un message est composé d'en-têtes et d'un corps.
  Ce corps est géré par la classe abstraite Body.
  Ce corps du message peut être soit :
    - composé d'une seule partie (classe MonoPart)
    - composé de plusieurs parties (classe MultiPart) et peut alors être :
      - une composition mixte, typiquement un texte de message avec des fichiers attachés (classe Mixed)
      - une alternative entre plusieurs éléments, typiquement un texte de message en plain/text et en Html (classe Alternative)
      - un ensemble d'éléments liés, typiquement un texte Html avec des images associées en ligne (classe Related)

  message ::= header+ + body
  header ::= content-type | Content-Transfer-Encoding | ...
  body ::= monoPart | multiPart
  monoPart ::= content-type + Content-Transfer-Encoding + contents
  content-type ::= string
  Content-Transfer-Encoding ::= string
  contents  ::= chaine d'octets
  multiPart ::= mixed | alternative | related | report
  mixed     ::= body + attachment+
  attachment ::= content-type + name + contents
  alternative ::= body+
  related  ::= text/html + inlineimage+
  inlineimage ::= content-type + Content-ID + contents
journal: |
  21/3/2020:
    - téléchargement d'une pièce jointe d'un message
    - ajout de l'utilisation d'un index pour analyser une bal
  20/3/2020:
    - ajout correction headers erronés
    - détection erreur sur http://localhost/rmbox/?action=get&mbox=Sent&offset=2002264646
      - les messages ajoutés par le calendrier ne sont pas terminées par une ligne vide
      - lors de la lecture le message est agrégé avec le suivant
    - -> mise en place d'un contournement utilisant la méthode Message::isStartOfMessage()
    - ajout d'un parse avec decalage par offset et non par numéro de message
    - ajout gestion format text/calendar et application/ics
  19/3/2020:
    - refonte de la gestion des messages multi-parties avec la hiérarchie de classe Body
    - fonctionne sur http://localhost/rmbox/?action=get&offset=1475300
    - affichage des images en dehors du fichier HTML
  18/3/2020:
    - prise en compte de l'en-tête Content-Transfer-Encoding dans la lecture du corps du message
classes:
*/


/*PhpDoc: classes
name: Body
title: abstract class Body - Corps d'un message ou partie
doc: |
methods:
*/
abstract class Body {
  protected $type; // le type de contenu = type MIME - string
  protected $headers=[]; // [ key -> string ] - les autres en-têtes
  protected $contents; // le contenu comme chaine d'octets évent. décodé en fonction de Content-Transfer-Encoding - string
  
  // crée un nouveau Body en fonction du type, les headers complémentaires sont utilisés pour les parties de multi-parties
  static function create(string $type, string $contents, array $headers=[]): Body {
    if (substr($type, 0, 15) == 'multipart/mixed')
      return new Mixed($type, $contents);
    elseif (substr($type, 0, 21) == 'multipart/alternative')
      return new Alternative($type, $contents);
    elseif (substr($type, 0, 17) == 'multipart/related')
      return new Related($type, $contents);
    else
      return new MonoPart($type, $contents);
  }
  
  // retire les headers et les renvoient séparés sous la forme [ key => value ]
  static function extractHeaders(array &$text): array {
    $headers = [];
    $key = '';
    while ($line = array_shift($text)) { // les headers s'arrête à la première ligne vide
      if (!in_array(substr($line, 0, 1), ["\t", ' '])) {
        $pos = strpos($line, ': ');
        $key = substr($line, 0, $pos);
        $line = substr($line, $pos+2);
        if (isset($headers[$key]))
          echo "headers[$key] == ",$headers[$key]," && = $line<br>\n";
        $headers[$key] = $line;
      }
      else {
        $headers[$key] .= ' '.substr($line, 1);
      }
      //echo "line=$line\n"; print_r($this);
    }
    foreach ($headers as $key => $value)
      $headers[$key] = @iconv_mime_decode($value);
    // Correction des headers erronés Content-type -> Content-Type
    if (isset($headers['Content-type'])) {
      $headers['Content-Type'] = $headers['Content-type'];
      unset($headers['Content-type']);
    }
    // Fin correction
    return $headers;
  }
  
  // construit un body défini par un texte passé sous la forme d'une liste de chaines qui commence par une liste de headers
  static function createFromPart(array $text): Body {
    //echo "<pre>Body::newFromPart(text="; print_r($text); echo ")</pre>\n";
    if (!$text) {
      //echo "<b>return</b> empty Body<br>\n";
      return new MonoPart('', '');
    }
    $headers = Body::extractHeaders($text);
    $type = $headers['Content-Type'] ?? '';
    unset($headers['Content-Type']);
    if (preg_match('!^multipart/(mixed|alternative|related); +boundary="([^"]*)"!', $type)) {
      //echo "<b>return</b> Body::new($type, text)<br>\n";
      return Body::create($type, implode("\n", $text), $headers);
    }
    else {
      //echo "<b>return</b> MonoPart()<br>\n";
      return new MonoPart($type, implode("\n", $text), $headers);
    }
  }
  
  // recopie les 3 paramètres dans les champs de l'objet
  function __construct(string $type, string $contents, array $headers=[]) {
    //echo "Body::__construct(type=$type, contents, headers)<br>\n";
    $this->type = $type;
    $this->contents = $contents;
    $this->headers = $headers;
  }

  // chaque objet doit être capable de s'afficher sous la forme d'un texte HTML
  // Si et ssi $debug est vrai alors affichage d'infos détaillées de debug
  abstract function asHtml(bool $debug): string;

  // Pour un fichier attaché renvoie son nom sinon null
  abstract function name(): ?string;
};

/*PhpDoc: classes
name: MonoPart
title: class MonoPart extends Body - Partie atomique
doc: |
methods:
*/
class MonoPart extends Body {
  // renvoie le contenu décodé en fonction du header Content-Transfer-Encoding
  function decodedContents(): string {
    $ctEncoding = $this->headers['Content-Transfer-Encoding'] ?? null;
    if (!$ctEncoding || ($ctEncoding == '8bit') || ($ctEncoding == '7bit'))
      return $this->contents;
    elseif ($ctEncoding == 'base64')
      return base64_decode($this->contents);
    elseif ($ctEncoding == 'quoted-printable')
      return quoted_printable_decode($this->contents);
    else
      throw new Exception("Content-Transfer-Encoding == '$ctEncoding' inconnu");
  }
  
  // retourne le code Html d'affichage de l'objet
  function asHtml(bool $debug): string {
    if ($debug) {
      $html = "<table border=1>\n";
      $html .= '<tr><td>Content-Type</td><td>'.$this->type."</td></tr>\n";
      foreach ($this->headers as $key => $value)
        $html .= "<tr><td>$key</td><td>".htmlentities($value)."</td></tr>\n";
      $html .= "<tr><td>contents</td><td>".$this->asHtml(false)."</td></tr>\n";
      $html .= "</table>\n";
      return $html;
    }
    if (($this->type == '') && ($this->contents == ''))
      return "Empty MonoPart\n";
    elseif (preg_match('!^text/(plain|html); charset="?([-a-zA-Z0-9]*)!', $this->type, $matches)) {
      $format = $matches[1];
      $charset = $matches[2];
      $html = '';
      try {
        $contents = $this->decodedContents();
      } catch (Exception $e) {
        $html .= "<b>Warning: dans MonoPart::asHtml() ".$e->getMessage()."</b>\n";
        $contents = $this->contents;
      }
      
      if (!in_array($charset, ['utf-8','UTF-8']))
        $contents = mb_convert_encoding($contents, 'utf-8', $charset);
      if ($format == 'plain')
        $html .= '<pre>'.htmlentities($contents).'</pre>';
      else // if ($format=='html')
        $html .= $contents;
      return $html;
    }
    elseif (preg_match('!^(image/(png|jpeg|gif))!', $this->type, $matches)) {
      $type = $matches[1];
      $ctEncoding = $this->headers['Content-Transfer-Encoding'] ?? null;
      if ($ctEncoding == 'base64') {
        return "<img src=\"data:$type;base64,".$this->contents."\">";
      }
      else {
        return "Warning: dans MonoPart::asHtml() Content-Transfer-Encoding == '$ctEncoding' inconnu\n";
      }
    }
    elseif (preg_match('!^(application/(pdf|msword|vnd\.oasis\.opendocument\.text)); name="([^"]+)"$!', $this->type, $matches)) {
      return "<a href='?action=dlAttached&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]"
        .(isset($_GET['debug']) ? "&amp;debug=true" : '')
        ."&amp;name=".urlencode($matches[3])."'>"
        ."Attachment type $matches[1], name=\"$matches[3]\"</a>\n";
    }
    elseif (preg_match('!^text/calendar!', $this->type)) {
      return '<pre><i>Content-Type: '.$this->type."</i>\n".htmlentities($this->contents).'</pre>';
    }
    elseif (preg_match('!^application/ics!', $this->type)) {
      return '<pre><i>Content-Type: '.$this->type."</i>\n".htmlentities($this->contents).'</pre>';
    }
    else {
      return "<b>Warning: dans MonoPart::asHtml() Unknown Content-Type '$this->type'</b>"
        .'<pre>'.htmlentities($this->contents).'</pre>';
    }
  }
  
  // Pour un fichier attaché renvoie son nom sinon null
  function name(): ?string {
    if (!preg_match('!^(application/(pdf|msword|vnd\.oasis\.opendocument\.text)); name="([^"]+)"$!', $this->type, $matches))
      return null;
    return $matches[3];
  }

  // génère le téléchargement d'un fichier attaché
  function download(bool $debug) {
    if ($debug) {
      echo "<pre>"; print_r($this); echo "</pre>\n"; die();
    }
    $contents = $this->decodedContents();
    header('Content-type: '.$this->type);
    header('Content-length: '. strlen($contents));
    if (isset($this->headers['Content-Disposition']))
      header('Content-Disposition: '.$this->headers['Content-Disposition']);
    echo $contents;
    die();
  }
};

/*PhpDoc: classes
name: MultiPart
title: abstract class MultiPart - Partie composée de plusieurs parties
doc: |
methods:
*/
abstract class MultiPart extends Body {
  // renvoie la boundary déduite du Content_Type
  function boundary(): string {
    if (preg_match('!^multipart/(mixed|alternative|related); +boundary="([^"]*)"!', $this->type, $matches))
      return $matches[2];
    else
      throw new Exception("MultiPart::boundary() impossible sur type='".$this->type."'");
  }
  
  // décompose le contenu en chacune des parties
  function parts(): array { // retourne un [ Body ]
    $text = explode("\n", $this->contents);
    $parts = []; // [ Body ]
    $part = []; // [ string ]
    foreach ($text as $line) {
      if (strpos($line, $this->boundary()) !== FALSE) {
        $parts[] = Body::createFromPart($part);
        $part = [];
      }
      else {
        $part[] = $line;
      }
    }
    //$parts[] = Body::createFromPart($part); // La dernière partie semble systématiquement vide
    array_shift($parts); // La première partie est vide ou inutile
    //echo "<pre>parts="; print_r($parts); echo "</pre>\n";
    return $parts;
  }
  
  /*function asHtml(): string {
    //return 'MultiPart::asHtml()'.'<pre>'.htmlentities($this->contents).'</pre>';
    $html = "MultiPart::asHtml()<br>\n";
    $html .= "<table border=1>\n";
    foreach ($this->parts() as $part) {
      $html .= "<tr><td>".$part->asHtml()."</td></tr>\n";
    }
    $html .= "</table>\n";
    return $html;
  }*/
  
  // Pour un fichier attaché renvoie son nom sinon null
  function name(): ?string { return null; }
};

/*PhpDoc: classes
name: Mixed
title: class Mixed extends MultiPart - Composition mixte, typiquement un texte de message avec des fichiers attachés
doc: |
methods:
*/
class Mixed extends MultiPart {
  // retourne le code Html d'affichage de l'objet
  function asHtml(bool $debug): string {
    $html = $debug ? "Mixed::asHtml()<br>\n" : '';
    $html .= "<table border=1>\n";
    foreach ($this->parts() as $part) {
      $html .= "<tr><td>".$part->asHtml($debug)."</td></tr>\n";
    }
    $html .= "</table>\n";
    return $html;
  }
  
  function dlAttached(string $name, bool $debug) {
    //echo "Mixed::dlAttached($name)<br>\n";
    foreach ($this->parts() as $part) {
      if ($part->name() == $name)
        $part->download($debug);
    }
  }
};

/*PhpDoc: classes
name: Alternative
title: class Alternative extends MultiPart - Alternative entre plusieurs éléments, typiquement un texte de message en texte brut et en Html
doc: |
methods:
*/
class Alternative extends MultiPart {
  function asHtml(bool $debug): string {
    if ($debug) {
      $html = "Alternative::asHtml()<br>\n";
      $html .= "<table border=1>\n";
      foreach ($this->parts() as $part) {
        $html .= "<tr><td>".$part->asHtml($debug)."</td></tr>\n";
      }
      $html .= "</table>\n";
      return $html;
    }
    else {
      $parts = $this->parts();
      return $parts[count($parts)-1]->asHtml($debug);
    }
  }
};

/*PhpDoc: classes
name: Related
title: class Related extends MultiPart - Ensemble d'éléments liés, typiquement un texte Html avec des images associées en ligne
doc: |
methods:
*/
class Related extends MultiPart {
  function asHtml(bool $debug): string {
    $html = $debug ? 'Related::asHtml()' : '';
    $html .= "<table border=1>\n";
    foreach ($this->parts() as $part) {
      $html .= "<tr><td>".$part->asHtml($debug)."</td></tr>\n";
    }
    $html .= "</table>\n";
    return $html;
  }
};


// utilisation d'un index
class IndexFile {
  protected $path; // chemin du fichier index
  protected $file; // descripteur du fichier index
  
  // teste l'existence de l'index
  static function exists(string $path) { return is_file("$path.idx"); }

  function __construct(string $path) {
    $this->path = "$path.idx";
    if (!($this->file = @fopen($this->path, 'r')))
      throw new Exception("Erreur d'ouverture de la mbox $path.idx");
  }

  function __destruct() { if ($this->file) fclose($this->file); }
  
  function size(): int { return filesize($this->path)/21; }
  
  function get(int $start): int {
    if ($start >= $this->size()) {
      $size = $this->size();
      die("Erreur d'accès $start à l'index qui contient $size enregistrements\n");
    }
    fseek($this->file, $start * 21);
    if (!fscanf($this->file, '%20d', $offset)) {
      $size = $this->size();
      die("Erreur d'accès $start à l'index qui contient $size enregistrements\n");
    }
    return $offset;
  }
};


/*PhpDoc: classes
name: Message
title: classe Message - gestion d'un message ainsi que son extraction à partir d'un fichier Mbox
doc: |
  Un message est composé d'en-têtes (header) et d'un corps (body) géré par la classe Body.
  Il est contenu dans un fichier Mbox à un certain décalage par rapport au début du fichier.

  class Message {
    protected $header=[]; // dictionnaire des en-têtes, key -> liste(string)
    protected $body; // texte correspondant au corps du message avec séparateur \n entre lignes
    protected $offset; // offset du message dans le fichier Mbox
methods:
*/
class Message {
  protected $header=[]; // dictionnaire des en-têtes, key -> liste(string)
  protected $body; // texte correspondant au corps du message avec séparateur \n entre lignes
  protected $offset; // offset du message dans le fichier mbox
  
  // detection du début d'un nouveau message avec $line
  // Gestion d'un bug dans le fichier des messages:
  // Lorsque le calendrier insère un message, celui-ci ne se termine pas par une ligne vide mais par une ligne boundary
  static function isStartOfMessage(string $precLine, string $line): bool {
    return (strncmp($line, 'From - ', 7)==0)
      && preg_match('!^From - [a-zA-Z]{3} [a-zA-Z]{3} \d\d \d\d:\d\d:\d\d \d\d\d\d$!', $line);
  }
  static function isStartOfMessage2(string $precLine, string $line): bool {
    return (strncmp($line, 'From ', 5)==0)
      && (($precLine == '') || (strncmp($precLine, '--Boundary', 10)==0) || (strncmp($precLine, '--------------', 14)==0));
  }
  
  /*PhpDoc: methods
  name: parse
  title: "static function parse(string $path, int &$start, int $maxNbre=10, array $criteria=[]): \\Generator -  retourne les premiers messages respectant les critères"
  doc: |
    Le paramètre $start est retourné avec la valeur à utiliser dans l'appel suivant ou -1 si le fichier a été entièrement parcouru.
    C'est la méthode la plus simple pour parcourir un fichier Mbox mais elle n'est pas très efficace.
    2 solutions pour un parcours plus efficace:
      - si on connait l'offset du message de départ alors utiliser la méthode parseUsingOffset()
      - sinon si un index a été créé alors utiliser parseWithIdx()
      - sinon créer un index et revenir au cas précédent
  */
  static function parse(string $path, int &$start, int $maxNbre=10, array $criteria=[]): \Generator {
    if (!($mbox = @fopen($path, 'r')))
      die("Erreur d'ouverture de mbox $path");
    $precLine = false; // la ligne précédente
    $msgTxt = []; // le message sous la forme d'une liste de lignes rtrimmed
    $no = 0; // le no courant de message
    $offset = 0; // offset de l'entegistrement courant
    while ($iline = fgets($mbox)) {
      $line = rtrim ($iline, "\r\n");
      if (self::isStartOfMessage($precLine, $line)) { // detection d'un nouveau message
        if (($precLine !== false) && $no++ >= $start) {
          $msg = new Message($msgTxt, $offset);
          if ($msg->match($criteria)) {
            yield $msg;
            if (--$maxNbre <= 0) {
              $start = $no;
              return;
            }
          }
        }
        $offset = ftell($mbox) - strlen($iline);
        //if ($no > 10000) die("fin ligne ".__LINE__);
        $msgTxt = [];
      }
      $msgTxt[] = $line; 
      $precLine = $line;
    }
    $msg = new Message($msgTxt, $offset);
    yield $msg;
    $start = -1;
    return;
  }
  
  /*PhpDoc: methods
  name: parse
  title: "static function parseWithIdx(string $path, int &$start, int $maxNbre=10, array $criteria=[]): \\Generator -  retourne les premiers messages respectant les critères en utilisant l'index s'il existe"
  doc: |
    Fonction similaire à parse(), utilise l'index s'il existe
  */
  static function parseWithIdx(string $path, int &$start, int $maxNbre=10, array $criteria=[]): \Generator {
    if (!IndexFile::exists($path)) {
      return self::parse($path, $start, $maxNbre, $criteria);
    }
    $idx = new IndexFile($path);
    $offset = $idx->get($start);
    return self::parseUsingOffset($path, $offset, $start, $maxNbre, $criteria);
  }
  
  /*PhpDoc: methods
  name: parseUsingOffset
  title: "static function parseUsingOffset(string $path, int &$offset, int &$start, int $maxNbre=10, array $criteria=[]): \\Generator - retourne les premiers messages respectant les critères à partir d'un offset"
  doc: |
    Le paramètre $offset est retourné avec la valeur à utiliser dans l'appel suivant ou -1 si le fichier a été entièrement parcouru
    Le paramètre $start est incrémenté du nombre de messages parcourus, qu'ils soient sélectionnés ou non.
  */
  static function parseUsingOffset(string $path, int &$offset, int &$start, int $maxNbre=10, array $criteria=[]): \Generator {
    if (!($mbox = @fopen($path, 'r')))
      die("Erreur d'ouverture de mbox $path");
    fseek($mbox, $offset);
    $precLine = false; // la ligne précédente
    $msgTxt = []; // le message sous la forme d'une liste de lignes rtrimmed
    while ($iline = fgets($mbox)) {
      $line = rtrim ($iline, "\r\n");
      if (($precLine !== false) && self::isStartOfMessage($precLine, $line)) { // detection d'un nouveau message
        $msg = new Message($msgTxt, $offset);
        $start++;
        $offset = ftell($mbox) - strlen($iline); // l'offset du message suivant
        if ($msg->match($criteria)) {
          yield $msg;
          if (--$maxNbre <= 0) {
            return;
          }
        }
        $msgTxt = [];
      }
      $msgTxt[] = $line; 
      $precLine = $line;
    }
    $msg = new Message($msgTxt, $offset);
    if ($msg->match($criteria))
      yield $msg;
    $offset = -1;
    $start = -1;
    return;
  }
  
  /*PhpDoc: methods
  name: get
  title: "static function get(string $path, int $offset): self  - retourne le message commencant à l'offset défini en paramètre"
  doc: |
  */
  static function get(string $path, int $offset): self {
    if (!($mbox = @fopen($path, 'r')))
      throw new Exception("Erreur d'ouverture du fichier mbox $path");
    fseek($mbox, $offset);
    $precLine = false; // la ligne précédente
    $msgTxt = []; // le message sous la forme d'une liste de lignes rtrimmed
    while ($line = fgets($mbox)) {
      $line = rtrim ($line, "\r\n");
      if (($precLine !== false) && self::isStartOfMessage($precLine, $line)) // détection d'un nouveau message
        break;
      $msgTxt[] = $line; 
      $precLine = $line;
    }
    return (new Message($msgTxt, $offset));
  }
  
  /*PhpDoc: methods
  name: body
  title: "function body(): Body - retourne le corps du message comme Body"
  doc: |
  */
  function body(): Body {
    $ctEncoding = $this->header['Content-Transfer-Encoding'][0] ?? null;
    if (!$ctEncoding || ($ctEncoding == '8bit') || ($ctEncoding == '7bit') || ($ctEncoding == '7BIT'))
      $contents = $this->body;
    elseif ($ctEncoding == 'base64')
      $contents = base64_decode($this->body);
    elseif ($ctEncoding == 'quoted-printable')
      $contents = quoted_printable_decode($this->body);
    else {
      echo "Warning: dans Message::body() Content-Transfer-Encoding == '$ctEncoding' inconnu<br>\n";
      $contents = $this->body;
    }
    return Body::create($this->header['Content-Type'][0] ?? '', $contents);
  }
  
  /*PhpDoc: methods
  name: offset
  title: "function offset(): int - retourne l'offset"
  doc: |
  */
  function offset(): int { return $this->offset; }
    
  /*PhpDoc: methods
  name: __construct
  title: "function __construct(array $txt, int $offset) - construit un objet Message"
  doc: |
  */
  function __construct(array $txt, int $offset) {
    //echo "<pre>Message::__construct()<br>\n";
    $this->offset = $offset;
    //foreach ($txt as $line) echo "$line\n";
    $this->header[''] = [ array_shift($txt) ]; // Traitement de la première ligne d'en-tete
    $key = '';
    while ($line = array_shift($txt)) { // le header s'arrête à la première ligne vide
      if (in_array(substr($line, 0, 1), ["\t", ' '])) {
        $this->header[$key][count($this->header[$key])-1] .= ' '.substr($line, 1);
      }
      else {
        $pos = strpos($line, ':');
        $key = substr($line, 0, $pos);
        $line = substr($line, $pos+2);
        if (!isset($this->header[$key]))
          $this->header[$key] = [ $line ];
        else
          $this->header[$key][] = $line;
      }
      //echo "line=$line\n"; print_r($this);
    }
    foreach ($this->header as $key => $values) {
      foreach ($values as $i => $atom) {
        //echo "atom=",htmlentities($atom),"<br>\n";
        $this->header[$key][$i] = @iconv_mime_decode($atom);
      }
    }
    // Correction des headers erronés Content-type -> Content-Type
    if (isset($this->header['Content-type'])) {
      $this->header['Content-Type'] = $this->header['Content-type'];
      unset($this->header['Content-type']);
    }
    // Fin correction
    $this->body = implode("\n", $txt);
    //echo "Fin Message::__construct() "; print_r($this); echo "</pre>\n";
  }
  
  /*PhpDoc: methods
  name: short_header
  title: "function short_header(): array - retourne un en-tête restreint sous la forme [key -> string]"
  doc: |
  */
  function short_header(): array {
    $short = [];
    foreach ([
        'Message-ID',
        'Return-Path',
        'Subject',
        'From',
        'Organization',
        'To',
        'Cc',
        'Date',
        'Content-Type',
        'Content-Transfer-Encoding'
      ] as $key) {
      if (isset($this->header[$key]))
        $short[$key] = $this->header[$key][0];
    }
    return array_merge($short,['offset'=>$this->offset]);
  }
  
  /*PhpDoc: methods
  name: asArray
  title: "function asArray(): array - retourne le message comme un array exportable en JSON"
  doc: |
  */
  function asArray(): array { return ['header'=> $this->short_header(), 'body'=> $this->body]; }
  
  /*PhpDoc: methods
  name: asArray
  title: "function match(array $criteria): bool - teste si la conjonction des critères est vérifiée"
  doc: |
    Les critères sont définis par un array [key -> value] avec:
      - soit key=='Message-ID' et le critère élémentaire est vrai ssi le messsageId du message est identique à value
      - soit key est un des header et le critère élémentaire est vrai ssi ce header est défini et si sa valeur matche
        l'expression régulière définie par value
  */
  function match(array $criteria): bool {
    foreach ($criteria as $key => $cvalue) {
      if ($key == 'Message-ID') {
        if ($this->header['Message-ID'][0] <> $cvalue)
          return false;
      }
      else {
        //echo "test match<br>\n";
        if (!isset($this->header[$key]))
          return false;
        $res = false;
        foreach ($this->header[$key] as $hvalstr) {
          if (preg_match("!$cvalue!", $hvalstr))
            $res = true;
        }
        if (!$res)
          return false;
      }
    }
    return true;
  }
  
  // télécharge une pièce jointe
  function dlAttached(string $name, bool $debug) { $this->body()->dlAttached($name, $debug); }
}
