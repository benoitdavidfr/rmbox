<?php
// rmbox/mbox.inc.php - gestion des messages d'un fichier mbox
// Affichage des sujets

class Message {
  protected $header=[]; // dictionnaire des en-têtes, key -> liste(string)
  protected $body=[]; // liste de lignes correspondant au corps du message
  
  static function parse(string $path, array $criteria=[], int $start=0, int $maxNbre=10): array {
    $result = [];
    if (!($mbox = @fopen($path, 'r')))
      die("Erreur d'ouverture de mbox $path");
    $precLine = "initialisée <> '' pour éviter une détection sur la première ligne"; // la ligne précédente
    $msgTxt = []; // le message sous la forme d'une liste de lignes rtrimmed
    while ($line = fgets($mbox)) {
      $line = rtrim ($line, "\r\n");
      if (($precLine == '') && (substr($line, 0, 4)=='From')) { // detection d'un nouveau message
        $result[] = new Message($msgTxt);
        if (count($result) >= $maxNbre)
          return $result;
        $msgTxt = [];
      }
      $msgTxt[] = $line; 
      $precLine = $line;
    }
    return $result;
  }
  
  function __construct(array $txt) {
    //echo "Message::__construct()<br>\n";
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
    $this->body = $txt;
  }
  
  function short_header(): array {
    $short = [];
    foreach (['Return-Path','Subject','To','From','Organization','Date','Content-Type'] as $key) {
      if (isset($this->header[$key]))
        $short[$key] = $this->header[$key];
    }
    return $short;
  }
  
  function asArray(): array { return ['header'=> $this->short_header(), 'body'=> $this->body]; }
}
