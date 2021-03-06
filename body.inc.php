<?php
/*PhpDoc:
name: body.inc.php
title: body.inc.php - définition de la classe Body et de ses sous-classes pour gérer le corps d'un message d'un fichier Mbox
doc: |
  Un message (géré par la classe Message) est composé d'en-têtes et d'un corps.
  Ce corps est géré par la classe abstraite Body qui peut être utilisée récursivement.
  Il peut soit :
    - être composé d'une seule partie (classe MonoPart)
    - être composé de plusieurs parties (classe MultiPart) et peut alors être :
      - une composition mixte, typiquement un texte de message avec des fichiers attachés (classe Mixed)
      - une composition de type report, on utilise alors aussi la classe Mixed,
      - une alternative entre plusieurs éléments, typiquement un texte de message en plain/text et en Html (classe Alternative)
      - un ensemble d'éléments liés, typiquement un texte Html avec des images associées en ligne (classe Related)
    - contenir un autre message (classe MessageRFC822)
journal: |
  28/5/2020:
    - ajout de 2 corrections dans Body::HeaderCorrections pour 'content-type' et 'content-transfer-encoding'
  30/3/2020:
    - ajout Body::simplType() et Body::treeOfContentTypes()
    - ajout Body::parts()
  29/3/2020:
    - affichage des messages inclus dans un autre message
  28/3/2020:
    - création du fichier à partir de mbox.inc.php
    - modification de Body::extractHeaders() pour mieux gérer les erreurs d'unicité de header
    - modification de MonPart::asHtml() pour mieux gérer certains cas
    - modif principe d'adressage d'une PJ
functions:
classes:
*/
require_once __DIR__.'/tree.inc.php';


/*PhpDoc: classes
name: Body
title: abstract class Body - Corps d'un message ou partie d'un corps
doc: |
  Les en-têtes sont stockés dans un dictionnaire indexé par la clé de l'en-tête ;
  la valeur contient soit une chaine soit une liste de chaines.
  Pour les clés définies dans SimpleHeaderKeys, la valeur est forcément une chaine.
methods:
*/
abstract class Body {
  // Corrections de la clé identifiant les headers d'un message ou d'un Body, utilisé dans extractHeaders()
  const HeaderCorrections = [
    'Content-type' => 'Content-Type',
    'content-type' => 'Content-Type', // ajout 28/5/2020
    'content-transfer-encoding' => 'Content-Transfer-Encoding', // ajout 28/5/2020
    'CC' => 'Cc',
  ];
  // Liste de Headers qui doivent être simples, cad que la valeur du dictionnaire est une chaine ; utilisée dans extractHeaders()
  const SimpleHeaderKeys = [
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
  ];
  protected $type; // string - le type MIME du contenu
  protected $headers=[]; // [ key -> (string | [ string ]) ] - les autres en-têtes
  protected $contents; // string - le contenu du body comme chaine d'octets
  protected $path; // chemin d'accès à partir du message stocké dans le fichier Mbox
  
  // crée un nouveau Body avec choix de la classe en fonction du type
  static function create(string $type, string $contents, array $headers, $path): Body {
    //if ((substr($type, 0, 15) == 'multipart/mixed') || (substr($type, 0, 14) == 'ultipart/mixed'))
    if (substr($type, 0, 15) == 'multipart/mixed')
      return new Mixed($type, $contents, $headers, $path);
    elseif (substr($type, 0, 16) == 'multipart/report')
      return new Mixed($type, $contents, $headers, $path);
    elseif (substr($type, 0, 21) == 'multipart/alternative')
      return new Alternative($type, $contents, $headers, $path);
    elseif (substr($type, 0, 17) == 'multipart/related')
      return new Related($type, $contents, $headers, $path);
    elseif (substr($type, 0, 14) == 'message/rfc822')
      return new MessageRFC822($type, $contents, $headers, $path);
    else
      return new MonoPart($type, $contents, $headers, $path);
  }
  
  // retire les headers et les renvoient séparés sous la forme [ key => (string | [ string ]) ] 
  // si la clé du header appartient à SimpleHeaderKeys alors la forme est [ key => string ]
  // Cette fonction est utilisée à la fois par Body::createFromPart() et Message::__construct()
  static function extractHeaders(array &$text): array {
    //echo "Body::extractHeaders()<br>\n";
    $stext = $text; // sauvegarde du texte en entrée avant modification
    $htext = []; // partie du texte correspondant aux headers
    while ($line = array_shift($text)) { // les headers s'arrêtent à la première ligne vide
      if (!in_array(substr($line, 0, 1), ["\t", ' ']))
        $htext[] = $line;
      else // si le premier caractère est un blanc ou un tab alors c'est une ligne de continuation
        $htext[count($htext)-1] .= ' '.substr($line, 1);
    }
    // à ce stade les headers sont extraits de $text et copiés dans $htext
    // la ligne vide entre les en-têtes et le corps est supprimée de $text et n'est pas copiée dans $htext
    // on construit dans un second temps le tableau des headers 
    $headers = [];
    foreach ($htext as $line) {
      // détection de la clé
      if (($pos = strpos($line, ':')) === FALSE) {
        echo "clé absente dans Body::extractHeaders() sur ";
        echo "<pre>headers="; print_r($headers);
        echo "<pre>stext="; print_r($stext);
        throw new Exception("clé absente dans Body::extractHeaders()");
      }
      $key = substr($line, 0, $pos);
      
      // Correction des clés de header erronées Content-type -> Content-Type
      if (isset(self::HeaderCorrections[$key])) {
        //echo "Correction de $key en ",self::HeaderCorrections[$key],"<br>\n";
        $key = self::HeaderCorrections[$key];
      }
      
      // Normalement le séparateur de la clé est la chaine est ': '
      // Mais dans certains cas on trouve ':' sans blanc, ex: http://localhost/rmbox/?action=get&mbox=0entrant&offset=3232652990
      if (substr($line, $pos+1, 1) == ' ')
        $line = substr($line, $pos+2);
      else
        $line = substr($line, $pos+1);
      if (($decodedVal = @iconv_mime_decode($line)) === FALSE)
        $decodedVal = $line;
      //echo "decodedVal=$decodedVal<br>\n";
      
      // stockage du header dans la variable headers
      if (!isset($headers[$key]))
        $headers[$key] = $decodedVal; // la première valeur est stockée directement
      elseif (in_array($key, self::SimpleHeaderKeys)) { // si la clé appartient à SimpleHeaderKeys
        $nheader = $decodedVal;
        if ($nheader <> $headers[$key]) // si les 2 valeurs sont distinctes alors alerte et conservation de la première
          echo "Erreur d'unicité dans Body::extractHeaders() pour headers[$key] == ",$headers[$key]," && line == $nheader<br>\n";
      }
      elseif (is_string($headers[$key])) // Si elle n'y appartient pas alors stockage dans un array
        $headers[$key] = [$headers[$key], $decodedVal]; // pour la seconde création de l'array
      else
        $headers[$key][] = $decodedVal; // à partir de la 3ème ajout dans l'array
    }
    //echo "<pre>Body::extractHeaders() returns: "; print_r($headers); echo "</pre>\n";
    return $headers;
  }
  
  static function simplType(string $ctype): string { // simplification du type
    static $applications = [
      'pdf' => 'doc',
      'vnd.ms-word' => 'doc',
      'vnd.ms-powerpoint' => 'doc',
      'msword' => 'doc',
      'vnd.ms-excel' => 'doc',
      'vnd.openxmlformats-officedocument.wordprocessingml.document' => 'doc',
      'vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'doc',
      'vnd.openxmlformats-officedocument.presentationml.presentation' => 'doc',
      'vnd.oasis.opendocument.text' => 'doc',
      'vnd.oasis.opendocument.presentation' => 'doc',
      'vnd.oasis.opendocument.spreadsheet' => 'doc',
    ];
    if (preg_match('!^multipart/([^;]+);!', $ctype, $matches))
      return strToUpper($matches[1]);
    elseif (preg_match('!^text/([^;]+)!', $ctype, $matches))
      return $matches[1];
    elseif (preg_match('!^(image|video)/!', $ctype, $matches))
      return $matches[1];
    elseif (preg_match('!^application/([^;]+)!', $ctype, $matches))
      return $applications[$matches[1]] ?? $matches[1];
    elseif (preg_match('!^(message)/rfc822!', $ctype, $matches))
      return $matches[1];
    else
      return $ctype;
  }
  
  // recopie les 4 paramètres dans les champs de l'objet
  function __construct(string $type, string $contents, array $headers, array $path) {
    //echo "Body::__construct(type=$type, contents, headers)<br>\n";
    $this->type = $type;
    $this->contents = $contents;
    $this->headers = $headers;
    $this->path = $path;
  }

  function type() { return $this->type; }
  
  function isMulti() { return false; }
  
  function parts(): array { return []; } // retourne un [ Body ]
  
  // balayage récursif des parties pour créer un arbre des types simplifiés
  function treeOfContentTypes(): Tree {
    $children = [];
    foreach ($this->parts() as $part)
      $children[] = $part->treeOfContentTypes();
    return new Tree(self::simplType($this->type()), $children);
  }
  
  // chaque objet doit être capable de s'afficher sous la forme d'un texte HTML
  // Si et ssi $debug est vrai alors affichage d'infos détaillées de debug
  abstract function asHtml(bool $debug): string;
};

/*PhpDoc: classes
name: MonoPart
title: class MonoPart extends Body - Partie atomique
doc: |
methods:
*/
class MonoPart extends Body {
  // liste des formats reconnus pour les fichiers attachés, utilisé dans un preg_match()
  static $attachFormats = [
    'application/octet-stream',
    'application/octet-steam', // bug rencontré http://localhost/rmbox/?action=get&mbox=0entrant&offset=3554317573
    'application/pdf',
    'application/msword',
    'application/vnd\.oasis\.opendocument\.text',
    'application/vnd\.openxmlformats-officedocument\.wordprocessingml\.document',
    'application/vnd\.openxmlformats-officedocument\.spreadsheetml\.sheet',
    'application/vnd\.openxmlformats-officedocument\.presentationml\.presentation'
  ];
  //Warning: dans MonoPart::asHtml() Unknown Content-Type 'application/vnd.openxmlformats-officedocument.wordprocessingml.document; x-unix-mode=0600; name="20200206_note open data_Cab BPoirsonVEcolab_Etalab.docx"'
    
  // retourne le contenu décodé en fonction du header Content-Transfer-Encoding
  function decodedContents(): string {
    //echo "<pre>this="; print_r($this); echo "</pre>\n";
    $ctEncoding = $this->headers['Content-Transfer-Encoding'] ?? '';
    switch (strToLower($ctEncoding)) {
      case '':
      case '8bit':
      case '7bit':
        return $this->contents;
      case 'base64':
        return base64_decode($this->contents);
      case 'quoted-printable':
        return quoted_printable_decode($this->contents);
      default:
        throw new Exception("Content-Transfer-Encoding == '$ctEncoding' inconnu");
    }
  }
  
  // retourne le code Html d'affichage de l'objet
  function asHtml(bool $debug): string {
    if ($debug) {
      $html = "<b>MonoPart</b>, path=".implode('/', $this->path)."<br>\n";
      $html .= "<table border=1>\n";
      //foreach ($this->headers as $key => $value)
        //$html .= "<tr><td>H:$key</td><td>$value</td></tr>\n";
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
    elseif ($this->type == 'message/delivery-status') {
      return '<pre><b>'.$this->type."</b><br>\n".htmlentities($this->contents).'</pre>';
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
    elseif (preg_match('!^('.implode('|', self::$attachFormats).');'
                      .'( +[^n][^;]*;)?'
                      .' +name=("([^"]+)"|[^;]+)!', $this->type, $matches)) {
      $name = $matches[4] ?? $matches[3];
      return "<a href='?action=dlAttached&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]"
        .(isset($_GET['debug']) ? "&amp;debug=true" : '')
        ."&amp;path=".implode('/', $this->path)."'>"
        ."Attachment type $matches[1], name=\"$name\"</a>\n";
    }
    elseif (preg_match('!^text/calendar!', $this->type)) {
      return '<pre><i>Content-Type: '.$this->type."</i>\n".htmlentities($this->decodedContents()).'</pre>';
    }
    elseif (preg_match('!^application/ics!', $this->type)) {
      return '<pre><i>Content-Type: '.$this->type."</i>\n".htmlentities($this->decodedContents()).'</pre>';
    }
    else {
      return "<b>Warning: dans MonoPart::asHtml() Unknown Content-Type '$this->type'</b>"
        .'<pre>'.htmlentities($this->contents).'</pre>';
    }
  }
  
  // Pour un fichier attaché renvoie son nom sinon null
  function name(): ?string {
    if (!preg_match('!^('.implode('|', self::$attachFormats).'); name="([^"]+)"$!', $this->type, $matches))
      return null;
    return $matches[2];
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
  function isMulti() { return true; }

  // renvoie la boundary déduite du Content_Type
  function boundary(): string {
    $pattern = '!^multipart/(mixed|alternative|related|report);'
        .'( +[^b][^;]*;)?'
        .' +boundary=("([^"]*)"|.*)!';
    if (preg_match($pattern, $this->type, $matches)) {
      //print_r($matches);
      return $matches[4] ?? $matches[3];
    }
    else
      throw new Exception("MultiPart::boundary() impossible sur type='".$this->type."'");
  }
  
  // décompose le contenu de la MultiPart en chacune des parties
  function parts(): array { // retourne un [ Body ]
    $text = explode("\n", $this->contents);
    $parts = []; // [ Body ] - la liste des parties
    $part = false; // [ string ] - la liste des lignes de la partie courante, false au début pour sauter la partie avant la limite
    $partno = 0;
    foreach ($text as $line) {
      if (strpos($line, $this->boundary()) !== FALSE) {
        if ($part !== false) {
          if (!$part) {
            $parts[] = new MonoPart('', '', []);
          }
          else {
            $headers = Body::extractHeaders($part);
            $type = $headers['Content-Type'] ?? '';
            unset($headers['Content-Type']);
            $parts[] = Body::create($type, implode("\n", $part), $headers, array_merge($this->path, [$partno++]));
          }
        }
        $part = [];
      }
      elseif ($part !== false)
        $part[] = $line;
    }
    //$parts[] = Body::createFromPart($part); // La dernière partie semble systématiquement vide
    //echo "<pre>parts="; print_r($parts); echo "</pre>\n";
    return $parts;
  }
};

/*PhpDoc: classes
name: Mixed
title: class Mixed extends MultiPart - Composition mixte, typiquement un texte de message avec des fichiers attachés
doc: |
  aussi utilisé pour 'multipart/report'
methods:
*/
class Mixed extends MultiPart {
  // retourne le code Html d'affichage de l'objet
  function asHtml(bool $debug): string {
    $html = $debug ? "<b>Mixed</b>, path=".implode('/', $this->path)."<br>\n" : '';
    $html .= "<table border=1>\n";
    foreach ($this->parts() as $part) {
      $html .= "<tr><td>".$part->asHtml($debug)."</td></tr>\n";
    }
    $html .= "</table>\n";
    return $html;
  }
  
  function subMessage(array $path): Message {
    echo "<b>Mixed::subMessage(path=",implode('/', $path),")</b><br>\n";
    $nopart = array_shift($path);
    return $this->parts()[$nopart]->subMessage($path);
  }
  
  function dlAttached(array $path, bool $debug) {
    //echo "Mixed::dlAttached(",implode('/', $path),")<br>\n";
    $nopart = array_shift($path);
    $this->parts()[$nopart]->download($debug);
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
      $html = "<b>Alternative</b>, path=".implode('/', $this->path)."<br>\n";
      $html .= "<table border=1>\n";
      foreach ($this->parts() as $part) {
        $html .= "<tr><td>".$part->asHtml($debug)."</td></tr>\n";
      }
      $html .= "</table>\n";
      return $html;
    }
    else {
      $parts = $this->parts();
      foreach (array_reverse($parts) as $part) {
        if (preg_match('!^text/(plain|html)!', $part->type))
          return $part->asHtml($debug);
      }
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
    $html = $debug ? "<b>Related</b>, path=".implode('/', $this->path)."<br>\n" : '';
    $html .= "<table border=1>\n";
    foreach ($this->parts() as $part) {
      $html .= "<tr><td>".$part->asHtml($debug)."</td></tr>\n";
    }
    $html .= "</table>\n";
    return $html;
  }
};

/*PhpDoc: classes
name: MessageRFC822
title: class MessageRFC822 extends Body - Message inclus dans un autre message
doc: |
  Un MessageRFC822 contient un autre Message.
  Bien qu'un MessageRFC822 ne contient qu'un seul Body, celui du message inclus,
  il renvoie true pour isMulti() et pour parts() un ensemble constitué du body du message inclus.
methods:
*/
class MessageRFC822 extends Body {
  function isMulti() { return true; }

  // génère le message contenu dans le Body
  function message(): Message {
    //Message::__construct() attent une ligne avant les headers, raison pour laquelle il faut ajouter une ligne
    return new Message(array_merge(['MessageRFC822'], explode("\n", $this->contents)), -1, false, $this->path);
  }
  
  function asHtml(bool $debug): string {
    $path = implode('/', $this->path);
    $href = "?action=get&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]&amp;path=".urlencode($path);
    if (1) { // affichage résumé du message
      $headers = $this->message()->short_headers();
      $html = "<table border=1><tr>\n";
      $html .= "<td><a href='$href'>M</a></td>\n";
      $html .= "<td>Date: $headers[Date]</td>\n";
      $html .= "<td>From: ".$headers['From']."</td>\n";
      $html .= "</tr></table>\n";
    }
    elseif (0) { // Affichage du texte brut
      $html = "<a href='$href'><b>MessageRFC822</b>, path=$path</a><br>\n";
      $html .= '<pre>'.htmlentities($this->contents)."</pre>\n";
    }
    elseif (0) { // affichage du texte ligne par ligne
      $html = "<a href='$href'><b>MessageRFC822</b>, path=$path</a><br>\n";
      foreach (explode("\n", $this->contents) as $no => $line)
        echo "$no> ",htmlentities($line),"<br>\n";
    }
    else { // affichage complet du message
      $html = "<a href='$href'><b>MessageRFC822</b>, path=$path</a><br>\n";
      $html .= $this->message()->asHtml($debug);
    }
    return $html;
  }
  
  function subMessage(array $path): Message {
    echo "<b>MessageRFC822::subMessage(path=",implode('/', $path),")</b><br>\n";
    if (!$path)
      return $this->message();
    else
      throw new Exception("Pas de cas connu, ce serait une multiple inclusion de messages");
  }
  
  function parts(): array { // retourne un [ Body ]
    return [$this->message()->body()];
  }
  
  // Pour un fichier attaché renvoie son nom sinon null
  //function name(): ?string { return null; }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


die("Aucun test défini\n");
