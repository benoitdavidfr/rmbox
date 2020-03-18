<?php
/*PhpDoc:
name: mbox.inc.php
title: mbox.inc.php - définition de la classe Message pour gérer les messages d'un fichier Mbox
doc: |
journal: |
  18/3/2020:
    - prise en compte de l'en-tête Content-Transfer-Encoding dans la lecture du corps du message
classes:
*/

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
  title: "function body(): string - retourne le corps du message en appliquant éventuellement la transformation définie par l'en-tête Content-Transfer-Encoding"
  doc: |
  */
  function body(): string {
    $ctEncoding = $this->header['Content-Transfer-Encoding'][0] ?? null;
    if (!$ctEncoding || ($ctEncoding == '8bit') || ($ctEncoding == '7bit'))
      return $this->body;
    if ($ctEncoding == 'base64')
      return base64_decode($this->body);
    if ($ctEncoding == 'quoted-printable')
      return quoted_printable_decode($this->body);
    echo "Warning: dans Message::body() Content-Transfer-Encoding == '$ctEncoding' inconnu<br>\n";
    return $this->body;
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
