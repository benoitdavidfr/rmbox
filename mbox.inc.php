<?php
/*PhpDoc:
name: mbox.inc.php
title: mbox.inc.php - définition de classes pour gérer les messages d'un fichier Mbox dont la classe Message
doc: |
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
  19/3/2020:
    - refonte de la gestion des messages multi-parties avec la hiérarchie de classe Body
    - fonctionne sur http://localhost/rmbox/?action=get&offset=1475300
  18/3/2020:
    - prise en compte de l'en-tête Content-Transfer-Encoding dans la lecture du corps du message
classes:
*/


// Corps d'un message ou partie
abstract class Body {
  protected $type; // le type de contenu = type MIME - string
  protected $headers=[]; // [ string ] - les autres en-têtes
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
  abstract function asHtml(): string;
};

// Corps en une seule partie
class MonoPart extends Body {
  // retourne le code Html d'affichage de l'objet
  function asHtml(): string {
    if (($this->type == '') && ($this->contents == ''))
      return "Empty MonoPart\n";
    elseif (preg_match('!^text/(plain|html); charset="?([-a-zA-Z0-9]*)!', $this->type, $matches)) {
      $format = $matches[1];
      $charset = $matches[2];
      $html = "<table border=1>\n";
      $html .= '<tr><td>Content-Type</td><td>'.$this->type."</td></tr>\n";
      foreach ($this->headers as $key => $value)
        $html .= "<tr><td>$key</td><td>$value</td></tr>\n";
      $ctEncoding = $this->headers['Content-Transfer-Encoding'] ?? null;
      if (!$ctEncoding || ($ctEncoding == '8bit') || ($ctEncoding == '7bit'))
        $contents = $this->contents;
      elseif ($ctEncoding == 'base64')
        $contents = base64_decode($this->contents);
      elseif ($ctEncoding == 'quoted-printable')
        $contents = quoted_printable_decode($this->contents);
      else {
        $html .= "<tr><td colspan=2>Warning: dans Message::body() Content-Transfer-Encoding == '$ctEncoding' inconnu</td></tr>\n";
        $contents = $this->contents;
      }
      if (!in_array($charset, ['utf-8','UTF-8']))
        $contents = mb_convert_encoding($contents, 'utf-8', $charset);
      if ($format=='plain')
        $html .= '<tr><td>contents</td><td><pre>'.htmlentities($contents).'</pre></td></tr>';
      else // html
        $html .= "<tr><td>contents</td><td>$contents</td></tr>\n";
      $html .= "</table>\n";
      return $html;
    }
    elseif (preg_match('!^(application/pdf); name="([^"]+)"$!', $this->type, $matches)) {
      return "Attachment type $matches[1], name=\"$matches[2]\"\n";
    }
    else {
      return "<b>Unknown Content-Type '$this->type'</b>"
        .'<pre>'.htmlentities($this->contents).'</pre>';
    }
  }
};

// Corps composé de plusieurs parties, chacune étant un corps
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
};

// Typiquement un texte de message avec des fichiers attachés
class Mixed extends MultiPart {
  function asHtml(): string {
    $html = "Mixed::asHtml()<br>\n";
    $html .= "<table border=1>\n";
    foreach ($this->parts() as $part) {
      $html .= "<tr><td>".$part->asHtml()."</td></tr>\n";
    }
    $html .= "</table>\n";
    return $html;
  }
};

// Typiquement un texte de message en plain/text et en Html
class Alternative extends MultiPart {
  function asHtml(): string {
    $html = "Alternative::asHtml()<br>\n";
    $html .= "<table border=1>\n";
    foreach ($this->parts() as $part) {
      $html .= "<tr><td>".$part->asHtml()."</td></tr>\n";
    }
    $html .= "</table>\n";
    return $html;
  }
};

// Typiquement un texte Html avec des images associées en ligne
class Related extends MultiPart {
  function asHtml(): string { return 'Related::asHtml()'; }
};


/*PhpDoc: classes
name: Message
title: classe Message - gestion des messages d'un fichier Mbox
doc: |
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
  
  /*PhpDoc: methods
  name: parse
  title: "static function parse(string $path, int &$start=0, int $maxNbre=10, array $criteria=[]): \\Generator - analyse un fichier mbox et retourne des messages respectant les critères"
  doc: |
    Le paramètre $start est retourné avec la valeur à utiliser dans l'appel suivant ou -1 si le fichier a été entièrement parcouru
    Il serait plus efficace d'utiliser un offset plutôt qu'un nbre de messages.
  */
  static function parse(string $path, int &$start=0, int $maxNbre=10, array $criteria=[]): \Generator {
    if (!($mbox = @fopen($path, 'r')))
      die("Erreur d'ouverture de mbox $path");
    $precLine = "initialisée <> '' pour éviter une détection sur la première ligne"; // la ligne précédente
    $msgTxt = []; // le message sous la forme d'une liste de lignes rtrimmed
    $no = 0; // le no courant de message
    $offset = 0; // offset de l'entegistrement courant
    while ($iline = fgets($mbox)) {
      $line = rtrim ($iline, "\r\n");
      if (($precLine == '') && (substr($line, 0, 4) == 'From')) { // detection d'un nouveau message
        if ($no++ >= $start) {
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
    $start = -1;
    return;
  }
  
  /*PhpDoc: methods
  name: parse
  title: "static function get(string $path, int $offset): self  - retourne le message commencant à l'offset défini en paramètre"
  doc: |
  */
  static function get(string $path, int $offset): self {
    if (!($mbox = @fopen($path, 'r')))
      throw new Exception("Erreur d'ouverture de mbox $path");
    fseek($mbox, $offset);
    $precLine = "initialisée <> '' pour éviter une détection sur la première ligne"; // la ligne précédente
    $msgTxt = []; // le message sous la forme d'une liste de lignes rtrimmed
    while ($line = fgets($mbox)) {
      $line = rtrim ($line, "\r\n");
      if (($precLine == '') && (substr($line, 0, 4) == 'From')) { // detection d'un nouveau message
        break;
      }
      $msgTxt[] = $line; 
      $precLine = $line;
    }
    return (new Message($msgTxt, $offset));
  }
  
  /*PhpDoc: methods
  name: body
  title: "function body(): Body - retourne le corps du message
  doc: |
  */
  function body(): Body {
    $ctEncoding = $this->header['Content-Transfer-Encoding'][0] ?? null;
    if (!$ctEncoding || ($ctEncoding == '8bit') || ($ctEncoding == '7bit'))
      $contents = $this->body;
    elseif ($ctEncoding == 'base64')
      $contents = base64_decode($this->body);
    elseif ($ctEncoding == 'quoted-printable')
      $contents = quoted_printable_decode($this->body);
    else {
      echo "Warning: dans Message::body() Content-Transfer-Encoding == '$ctEncoding' inconnu<br>\n";
      $contents = $this->body;
    }
    return Body::create($this->header['Content-Type'][0], $contents);
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
    //echo "Message::__construct()<br>\n";
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
    $this->body = implode("\n", $txt);
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
        'To',
        'From',
        'Organization',
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
}
