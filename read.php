<?php
/*PhpDoc:
name: read.php
title: index.php - lecture d'un fichier Mbox (https://fr.wikipedia.org/wiki/Mbox)
doc: |
journal: |
  20/3/2020:
    - ajout des commandes listOffset, getOffset, offset et testDebordement
  18/3/2020:
    initialisation
*/

//$path = '0entrant';
$path = 'Sent';

require_once __DIR__.'/mbox.inc.php';

//echo "argc=$argc, argv="; print_r($argv);
if ($argc == 1) { // menu
  echo "usage: php $argv[0] <cmde> [<params>]\n";
  echo "Les commandes:\n";
  echo "  - list [{start} [{max}]]: liste les en-tetes à partir de {start} (défaut 0) avec maximum max messages (défaut 10)\n";
  echo "  - listOffset {offset} [{max}]: liste les en-tetes à partir de {offset} avec maximum max messages (défaut 10)\n";
  echo "  - get {Message-ID} : lit le message identifié par {Message-ID}\n";
  echo "  - getOffset {offset} : lit le message commencant à l'offset {offset}\n";
  echo "  - offset {offset} : lit le fichier commencant à l'offset {offset}\n";
  //echo "  - testDebordement : test du débordement d'un entier\n";
  die();
}

if ($argv[1] == 'list') {
  foreach (Message::parse($path, $argv[2] ?? 0, $argv[3] ?? 10, []) as $msg) {
    echo json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
  }
  die();
}

if ($argv[1] == 'listOffset') {
  if ($argc < 3)
    die("Erreur paramètre obligatoire\n");
  $offset = $argv[2];
  foreach (Message::parseUsingOffset($path, $offset, $argv[3] ?? 10, []) as $msg) {
    echo json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
  }
  echo "offset=$offset\n";
  die();
}
  
if ($argv[1] == 'get') {
  if ($argc < 3)
    die("Erreur paramètre Message-ID obligatoire\n");
  $msgs = Message::parse($path, 0, 1, ['Message-ID'=> "<$argv[2]>"]);
  echo json_encode(['header'=> $msgs[0]->short_header(), 'body'=> $msgs[0]->body()],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
  die();
}

if ($argv[1] == 'getOffset') {
  if ($argc < 3)
    die("Erreur paramètre offset obligatoire\n");
  $msg = Message::get($path, $argv[2]);
  echo json_encode(['header'=> $msg->short_header(), 'body'=> $msg->body()],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
  die();
}

if ($argv[1] == 'offset') {
  if ($argc < 3)
    die("Erreur paramètre offset obligatoire\n");
  if (!($mbox = @fopen($path, 'r')))
    throw new Exception("Erreur d'ouverture de mbox $path");
  fseek($mbox, $argv[2]);
  while (FALSE !== ($line = fgets($mbox))) {
    echo $line;
  }
  die();
}

if ($argv[1] == 'testDebordement') { // Test nombre entier maximum
  $nbrEntier = 65536;
  var_dump($nbrEntier);
  $nbrEntier = $nbrEntier * $nbrEntier * 100;
  var_dump($nbrEntier);
  die();
}

die("Aucun traitement reconnu\n");
