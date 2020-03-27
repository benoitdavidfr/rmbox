<?php
/*PhpDoc:
name: cmde.php
title: cmde.php - exploitation d'un fichier Mbox en mode ligne de commande
doc: |
journal: |
  26/3/2020:
    - ajout de la commande listContentType en lien avce ctype.inc.php
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

//$mbox = '0entrant';
//$mbox = 'Sent';
$mbox = '../baltest';
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
  echo "  - findSimplContentType {simplContentType} : retrouve les Content-Type détaillés à partir du simplifié\n";
  echo "  - parseContentTypes : analyse les Content-Type\n";
  die();
}

if ($argv[1] == 'list') {
  $start = $argv[2] ?? 0;
  foreach (Message::parseWithIdx($path, $start, $argv[3] ?? 10, []) as $msg) {
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
  
if ($argv[1] == 'get') { // lit le message commencant à l'offset {offset}
  if ($argc < 3)
    die("Erreur paramètre offset obligatoire\n");
  $msg = Message::get($path, $argv[2]);
  echo json_encode(['header'=> $msg->short_header(), 'body'=> $msg->body()],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
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

/*class Tree {
  protected $label; // string
  protected $children; // [ Tree ]
  
  function __construct(string $label, array $children) {
    $this->label = $label;
    $this->children = $children;
  }
  
  function show(int $level=0) {
    echo str_repeat('----', $level),$this->label,"\n";
    foreach ($this->children as $child) {
      $child->show($level+1);
    }
  }
};*/

/*function treeOfContentTypes(string $ctype, Body $body): Tree {
  $children = [];
  foreach ($body->parts() as $part) {
    //echo "part's type=",$part->type(),"\n";
    if (!$part->isMulti()) {
      $children[] = new Tree($part->type(), []);
    }
    else {
      $children[] = treeOfContentTypes($part->type(), $part);
    }
  }
  return new Tree($ctype, $children);
  //die("FIN ligne ".__LINE__."\n");
}*/

// balayage recursif des parties pour y récupérer les ctypes
function listOfContentTypes(&$fout, Body $body) {
  foreach ($body->parts() as $part) {
    fwrite($fout, $part->type()."\n");
    if ($part->isMulti())
      listOfContentTypes($fout, $part);
  }
}

// liste les Content-Type et crée le fichier contentTypes.txt des libellés
if ($argv[1] == 'listContentTypes') {
  require_once 'ctype.inc.php';

  $start = $argv[2] ?? 0;
  $fout = fopen('contentTypes.txt', 'w');
  foreach (Message::parse($path, $start, $argv[3] ?? 99999, []) as $msg) {
    echo "Message offset=",$msg->short_header()['offset'],"\n";
    $contentType = $msg->short_header()['Content-Type'] ?? '';
    fwrite($fout, "$contentType\n");
    if (1 && CType::testIsMulti($contentType)) { // balaie récursivement les parties pour récupérer les ctypes
      //echo "Content-Type=$contentType\n";
      $body = Message::get($path, $msg->short_header()['offset'])->body();
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
  require_once 'ctype.inc.php';
  
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

die("Aucun traitement reconnu\n");
