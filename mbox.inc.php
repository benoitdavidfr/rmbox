<?php
// rmbox/mbox.inc.php - gestion des messages d'un fichier mbox
// Affichage des sujets

class Message {
  protected $header=[]; // dictionnaire des en-têtes, key -> liste(string)
  protected $body=[]; // texte correspondant au corps du message avec séparateur \n entre lignes
  protected $offset; // offset du message dans le fichier
  
  // analyse un fichier mbox et retourne des messages respectant les critères
  static function parse(string $path, int &$start=0, int $maxNbre=10, array $criteria=[]): array {
    $result = [];
    if (!($mbox = @fopen($path, 'r')))
      die("Erreur d'ouverture de mbox $path");
    $precLine = "initialisée <> '' pour éviter une détection sur la première ligne"; // la ligne précédente
    $msgTxt = []; // le message sous la forme d'une liste de lignes rtrimmed
    $no = 0;
    $offset = 0; // offset de l'entegistrement courant
    while ($iline = fgets($mbox)) {
      $line = rtrim ($iline, "\r\n");
      if (($precLine == '') && (substr($line, 0, 4) == 'From')) { // detection d'un nouveau message
        if ($no++ >= $start) {
          $msg = new Message($msgTxt, $offset);
          if ($msg->match($criteria))
            $result[] = $msg;
          if (count($result) >= $maxNbre) {
            $start = $no;
            return $result;
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
    return $result;
  }
  
  // retourne le message commencant à l'offset défini
  static function get(string $path, int $offset): self {
    if (!($mbox = @fopen($path, 'r')))
      die("Erreur d'ouverture de mbox $path");
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
  
  function body() { return $this->body; }
  function offset() { return $this->offset; }
    
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
  
  function short_header(): array {
    $short = [];
    foreach (['Message-ID','Return-Path','Subject','To','From','Organization','Date','Content-Type'] as $key) {
      if (isset($this->header[$key]))
        $short[$key] = $this->header[$key];
    }
    return array_merge($short,['offset'=>$this->offset]);
  }
  
  function asArray(): array { return ['header'=> $this->short_header(), 'body'=> $this->body]; }
  
  // conjonction des différents critères
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
