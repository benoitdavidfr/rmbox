<?php
/*PhpDoc:
name: cmde.php
title: cmde.php - exploitation d'un fichier Mbox en mode ligne de commande
doc: |
journal: |
  30/3/2020:
    - transfert Message::struct() dans Message
  26/3/2020:
    - ajout de la commande listContentType en lien avec ctype.inc.php
  25/3/2020:
    - ajout de la création d'un index
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
//$mbox = '../baltest';
//$mbox = '../listes/ogc.mbox';

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
  echo "  - mboxes : liste des boites aux lettres\n";
  echo "  - buildIdx : fabrique un index pour la Bal $mbox\n";
  echo "  - parseWithIdx [{start} [{max}]] : liste les en-têtes avec parseWithIdx()\n";
  echo "  - listContentTypes : liste les Content-Type à partir de fichier Mbox\n";
  echo "  - parseContentTypes : analyse les Content-Type\n";
  echo "  - listStruct [{start} [{max}]] : liste les structures possibles des messages\n";
  echo "  - findStruct {struct} : trouve les nos de messages correspondant à la structure de message\n";
  die();
}

if ($argv[1] == 'list') {
  $start = $argv[2] ?? 0;
  foreach (Message::parseWithIdx($path, $start, $argv[3] ?? 10, []) as $msg) {
    echo json_encode($msg->short_headers(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
  }
  die();
}

if ($argv[1] == 'listOffset') {
  if ($argc < 3)
    die("Erreur paramètre obligatoire\n");
  $offset = $argv[2];
  foreach (Message::parseUsingOffset($path, $offset, $argv[3] ?? 10, []) as $msg) {
    echo json_encode($msg->short_headers(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
  }
  echo "offset=$offset\n";
  die();
}
  
if ($argv[1] == 'get') { // lit le message commencant à l'offset {offset}
  if ($argc < 3)
    die("Erreur paramètre offset obligatoire\n");
  $msg = Message::get($path, $argv[2]);
  echo json_encode(['header'=> $msg->short_headers(), 'body'=> $msg->body()],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
  die();
}

if ($argv[1] == 'getById') { // lit le message identifié par {Message-ID}
  if ($argc < 3)
    die("Erreur paramètre Message-ID obligatoire\n");
  $msgs = Message::parse($path, 0, 1, ['Message-ID'=> "<$argv[2]>"]);
  echo json_encode(['header'=> $msgs[0]->short_headers(), 'body'=> $msgs[0]->body()],
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

{/*PhpDoc: functions
name: readfiles
title: "function readfiles(string $dir, bool $recursive=false): array - Lecture des fichiers locaux du répertoire $dir"
doc: |
  Le système d'exploitation utilise ISO 8859-1, toutes les données sont gérées en UTF-8
  Si recursive est true alors renvoie l'arbre
*/}
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
    //echo json_encode($msg->short_headers(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
    fprintf($idxfile, "%20d\n", $msg->offset());
  }
  echo "Index construit pour $mbox\n";
  die();
}

if ($argv[1] == 'parseWithIdx') { // Test parseWithIdx
  $start = $argv[2] ?? 0;
  foreach (Message::parseWithIdx($path, $start, $argv[3] ?? 10, []) as $msg) {
    echo json_encode($msg->short_headers(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
  }
  die();
}

require_once 'ctype.inc.php';

// liste les Content-Type et crée le fichier contentTypes.txt des libellés
if ($argv[1] == 'listContentTypes') {
  
  function listOfContentTypes(&$fout, Body $body) { // balayage recursif des parties pour y récupérer les ctypes
    foreach ($body->parts() as $part) {
      fwrite($fout, $part->type()."\n");
      if ($part->isMulti())
        listOfContentTypes($fout, $part);
    }
  }

  $start = $argv[2] ?? 0;
  $fout = fopen('contentTypes.txt', 'w');
  foreach (Message::parse($path, $start, $argv[3] ?? 99999, []) as $msg) {
    echo "Message offset=",$msg->short_headers()['offset'],"\n";
    $contentType = $msg->short_headers()['Content-Type'] ?? '';
    fwrite($fout, "$contentType\n");
    if (1 && CType::testIsMulti($contentType)) { // balaie récursivement les parties pour récupérer les ctypes
      //echo "Content-Type=$contentType\n";
      $body = Message::get($path, $msg->short_headers()['offset'])->body();
      //echo "treeOfContentTypes:\n";
      //treeOfContentTypes($contentType, $body)->show();
      //treeOfContentTypes($contentType, $body);
      listOfContentTypes($fout, $body);
    }
  }
  fclose($fout);
  die();
}

// exploite le fichier contentTypes.txt pour effectuer une analyse des différents Content-Type
if ($argv[1] == 'parseContentTypes') {
  
  if (!($fin = fopen('contentTypes.txt', 'r')))
    die("Erreur d'ouverture du fichier contentTypes.txt\n");
  $types = [];
  $charsets = [];
  $subtypes = [];
  while ($contentType = fgets($fin)) {
    $contentType = rtrim($contentType);
    //echo "contentType=$contentType -> $nbre\n";
    $cType = CType::create($contentType);
    if (!$cType->isMulti()) {
      if (!in_array($cType->type(), $types))
        $types[] = $cType->type();
      if (!in_array($cType->charset(), $charsets))
        $charsets[] = $cType->charset();
    }
    else {
      //echo "subtype=",$cType->subtype(),"\n";
      if (!in_array($cType->subtype(), $subtypes))
        $subtypes[] = $cType->subtype();
    }
  }
  echo "Mono:\n";
  echo "types="; print_r($types);
  echo "charsets="; print_r($charsets);
  echo "Multi:\n";
  echo "subtypes="; print_r($subtypes);
  die();
}

// liste les structures des messages, fabrique un fichier struct.txt [ {struct}\t{no}\n ] et en sortie un fichier [ {struct}\n ]
// Cela permet facilement d'une part de trier et de rendre uniques les lignes en sortie
// et d'autre part de conserver le numéro des messages ayant produit la structure afin de le retrouver facilement
if ($argv[1] == 'listStruct') {
  if (!($fstruct = fopen('struct.txt', 'w')))
    die("Erreur d'ouverture du fichier struct.txt\n");
  fputs($fstruct, "# Fichier généré par '".implode(' ', $argv)."' le ".date(DATE_COOKIE)."\n");
  $start = $argv[2] ?? 0;
  foreach (Message::parseWithIdx($path, $start, $argv[3] ?? -1, []) as $msg) {
    //echo "Message offset=",$msg->short_headers()['offset'],"\n";
    $contentType = $msg->short_headers()['Content-Type'] ?? '';
    if (CType::testIsMulti($contentType)) {
      //echo "Content-Type=$contentType\n";
      $struct = Message::get($path, $msg->short_headers()['offset'])->struct();
      fprintf($fstruct, "%s\t%d\n", $struct, $start-1);
      echo "$struct\n";
    }
  }
  fclose($fstruct);
  die();
}

if ($argv[1] == 'findStruct') { // trouve les nos de messages correspondant à la structure de message
  if ($argc < 3)
    die("Erreur paramètre struct obligatoire\n");
  if (!($fstruct = fopen('struct.txt', 'r')))
    die("Erreur d'ouverture du fichier struct.txt\n");
  fgets($fstruct); // lecture de l'en-tête
  while ($buff = fgets($fstruct)) {
    $buff = rtrim($buff);
    list($json, $no) = explode("\t", $buff);
    if ($json == $argv[2])
      echo "$no\n";
  }
  die();
}

die("Aucun traitement reconnu pour $_GET[action]\n");
