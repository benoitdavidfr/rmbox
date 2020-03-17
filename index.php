<?php
// rmbox/index.php - lecture d'un fichier mbox (https://fr.wikipedia.org/wiki/Mbox)
// Affichage des sujets

$path = '0entrant';

if (!($mbox = @fopen($path,'r')))
  die("Erreur d'ouverture de mbox");

if (0) { // Affichage des premières lignes avec la version dump - la fin de ligne est \r\n
  $line = "\r\n";
  echo "<table border=1>\n";
  for($i=0; $i< strlen($line); $i++) {
    $c = substr($line, $i, 1);
    printf("<td>%x</td>", ord($c));
  }
  echo "</tr>";
  echo "</table>\n";

  echo "<table border=1>\n";
  $nol = 0;
  while ($line = fgets($mbox)) {
    $line = rtrim ($line, "\r\n");
    echo "<tr><td>",str_replace([' ',"\t"], ['&nbsp;','\t'], htmlentities($line)),"</td><td><table border=1>";
    echo "<tr>";
    for($i=0; $i< strlen($line); $i++) {
      $c = substr($line, $i, 1);
      echo "<td>",htmlentities($c),"</td>";
    }
    echo "</tr>";
    echo "<tr>";
    for($i=0; $i< strlen($line); $i++) {
      $c = substr($line, $i, 1);
      printf("<td>%x</td>", ord($c));
    }
    echo "</tr>";
    echo "</table></td></tr>\n";
    if (++$nol >= 400)
      break;
  }
}

class Message {
  protected $header=[]; // dictionnaire des en-têtes, key -> liste(string)
  protected $body=[]; // liste de lignes correspondant au corps du message
  
  function __construct(array $txt) {
    echo "Message::__construct()<br>\n";
    foreach ($txt as $line)
      echo "$line\n";
    $this->header[''] = [ array_shift($txt) ]; // Traitement de la première ligne d'en-tete
    $key = '';
    while ($line = array_shift($txt)) { // le header s'arrête à la première ligne vide
      if (in_array(substr($line, 0, 1), ["\t", ' '])) {
        $this->header[$key][count($this->header[$key])-1] .= ' '.substr($line, 1);
      }
      else {
        $pos = strpos($line, ':');
        $key = substr($line, 0, $pos);
        if (!isset($this->header[$key]))
          $this->header[$key] = [ $line ];
        else
          $this->header[$key][] = $line;
      }
      //echo "line=$line\n"; print_r($this);
    }
    $this->body = $txt;
  }
}


$precLine = "initialisée <> '' pour éviter une détection sur la première ligne"; // la ligne précédente
$msgTxt = []; // le message sous la forme d'une liste de lignes rtrimmed
while ($line = fgets($mbox)) {
  $line = rtrim ($line, "\r\n");
  if (($precLine == '') && (substr($line, 0, 4)=='From')) { // detection d'un nouveau message
    $msg = new Message($msgTxt);
    print_r($msg);
    die("FIN ligne ".__LINE__."\n");
    $msgTxt = [];
  }
  $msgTxt[] = $line; 
  $precLine = $line;
}

