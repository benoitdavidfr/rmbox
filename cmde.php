<?php
/*PhpDoc:
name: cmde.php
title: cmde.php - exploitation d'un fichier Mbox en mode ligne de commande
doc: |
journal: |
  21/3/2020:
    - ajout de la création d'un index
  20/3/2020:
    - ajout des commandes listOffset, getOffset, offset et testDebordement
  18/3/2020:
    initialisation
functions:
*/

$mbox = '0entrant';
//$mbox = 'Sent';
//$mbox = 'test';

$path = __DIR__.'/mboxes/'.$mbox;

require_once __DIR__.'/mbox.inc.php';

//echo "argc=$argc, argv="; print_r($argv);
if ($argc == 1) { // menu
  echo "usage: php $argv[0] <cmde> [<params>]\n";
  echo "Les commandes:\n";
  echo "  - list [{start} [{max}]]: liste les en-tetes à partir de {start} (défaut 0) avec maximum max messages (défaut 10)\n";
  echo "  - listOffset {offset} [{max}]: liste les en-tetes à partir de {offset} avec maximum max messages (défaut 10)\n";
  echo "  - get {offset} : lit le message commencant à l'offset {offset}\n";
  echo "  - getById {Message-ID} : lit le message identifié par {Message-ID}\n";
  echo "  - offset {offset} : affiche le fichier commencant à l'offset {offset}\n";
  //echo "  - testDebordement : test du débordement d'un entier\n";
  echo "  - testSom : recherche des débuts de messages incorrects\n";
  echo "  - mboxes : liste des boites aux lettres\n";
  echo "  - buildIdx : fabrique un index pour la Bal $mbox\n";
  die();
}

if ($argv[1] == 'list') {
  $start = $argv[2] ?? 0;
  foreach (Message::parse($path, $start, $argv[3] ?? 10, []) as $msg) {
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
  
if ($argv[1] == 'getById') { // lit le message identifié par {Message-ID}
  if ($argc < 3)
    die("Erreur paramètre Message-ID obligatoire\n");
  $msgs = Message::parse($path, 0, 1, ['Message-ID'=> "<$argv[2]>"]);
  echo json_encode(['header'=> $msgs[0]->short_header(), 'body'=> $msgs[0]->body()],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
  die();
}

if ($argv[1] == 'get') { // lit le message commencant à l'offset {offset}
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

// From - Mon Jul 04 08:26:16 2016
function isStartOfMessage(string $precLine, string $line): bool {
  return (strncmp($line, 'From - ', 7)==0)
    && preg_match('!^From - [a-zA-Z]{3} [a-zA-Z]{3} \d\d \d\d:\d\d:\d\d \d\d\d\d$!', $line);
}

if ($argv[1] == 'testSom') { // recherche des débuts de messages incorrects
  if (!($mbox = @fopen($path, 'r')))
    die("Erreur d'ouverture de mbox $path");
  $precLine = "initialisée <> '' pour éviter une détection sur la première ligne"; // la ligne précédente
  while ($iline = fgets($mbox)) {
    $line = rtrim ($iline, "\r\n");
    if ((strncmp($line, 'From ', 5)==0) xor isStartOfMessage($precLine, $line)) {
      echo "$precLine\n$line\n--\n";
    }
    $precLine = $line;
  }
  die();
}

/*PhpDoc: functions
name: readfiles
title: "function readfiles(string $dir, bool $recursive=false): array - Lecture des fichiers locaux du répertoire $dir"
doc: |
  Le système d'exploitation utilise ISO 8859-1, toutes les données sont gérées en UTF-8
  Si recursive est true alors renvoie l'arbre
*/
function readfiles(string $dir, bool $recursive=false): array { // lecture des nom, type et date de modif des fichiers d'un rép.
  $dirIso = utf8_decode($dir);
  if (!$dh = opendir($dirIso))
    die("Ouverture de $dir impossible");
  $files = [];
  while (($filename = readdir($dh)) !== false) {
    if (in_array($filename, ['.','..']))
      continue;
    $file = [
      'name'=> utf8_encode($filename),
      'type'=> filetype("$dirIso/$filename"), 
      'mdate'=>date ("Y-m-d H:i:s", filemtime("$dirIso/$filename")),
    ];
    if (($file['type'] == 'dir') && $recursive)
      $file['content'] = readfiles($dir.'/'.utf8_encode($filename), $recursive);
    $files[$file['name']] = $file;
  }
  closedir($dh);
  return $files;
}

if ($argv[1] == 'mboxes') { // liste des Bal
  $files = readfiles('mboxes');
  ksort($files);
  foreach (array_keys($files) as $filename) {
    if (preg_match('!\.(msf)$!', $filename))
      continue;
    echo "$filename\n";
  }
  die();
}

if ($argv[1] == 'buildIdx') { // fabrique un index pour la Bal $mbox
  if (!($idxfile = @fopen("$path.idx", 'w')))
    die("Erreur d'ouverture de mbox $path.idx");
  $start = 0;
  foreach (Message::parse($path, $start, 999999, []) as $msg) {
    //echo json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
    fprintf($idxfile, "%20d\n", $msg->offset());
  }
  echo "Index construit pour $mbox\n";
  die();
}

if ($argv[1] == 'parseWithIdx') { // Test parseWithIdx
  $start = $argv[2] ?? 0;
  foreach (Message::parseWithIdx($path, $start, $argv[3] ?? 10, []) as $msg) {
    echo json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
  }
  die();
}

die("Aucun traitement reconnu\n");
