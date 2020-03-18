<?php
/*PhpDoc:
name: read.php
title: index.php - lecture d'un fichier Mbox (https://fr.wikipedia.org/wiki/Mbox)
doc: |
journal: |
  18/3/2020:
    initialisation
*/

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

//echo "argc=$argc, argv="; print_r($argv);
if ($argc == 1) {
  echo "usage: php $argv[0] <cmde> [<params>]\n";
  echo "Les commandes:\n";
  echo "  - list [{start} [{max}]]: liste les en-tetes à partir de {start} (défaut 0) avec maximum max messages (défaut 10)\n";
  echo "  - get {Message-ID} : lit le message identifié par {Message-ID}\n";
  die();
}

if ($argv[1] == 'list') {
  foreach (Message::parse($path, $argv[2] ?? 0, $argv[3] ?? 10, []) as $msg) {
    echo json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
  }
  die();
}

if ($argv[1] == 'get') {
  if ($argc < 3)
    die("Erreur paramètre obligatoire\n");
  $msgs = Message::parse($path, 0, 1, ['Message-ID'=> "<$argv[2]>"]);
  echo json_encode(['header'=> $msgs[0]->short_header(), 'body'=> $msgs[0]->body()],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
}

die("Aucun traitement reconnu\n");
