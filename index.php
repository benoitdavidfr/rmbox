<?php
// rmbox/index.php - lecture d'un fichier mbox (https://fr.wikipedia.org/wiki/Mbox)
// Affichage des en-tetes

$path = '0entrant';

require_once __DIR__.'/mbox.inc.php';

if (0) { // Affichage des premières lignes avec la version dump - la fin de ligne est \r\n
  if (!($mbox = @fopen($path,'r')))
    die("Erreur d'ouverture de mbox");

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

foreach (Message::parse($path) as $msg) {
  echo json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
}
