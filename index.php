<?php
/*PhpDoc:
name: index.php
title: index.php - navigation dans des fichiers Mbox en mode Web
doc: |
  - liste des messages respectant certains critères
  - affichage d'un message particulier avec notamment accès aux pièces-jointes
  - affichage de la liste des Content-Type et des messages correspondants à chacun

  Une bal peut être ou non indexée. Pour l'indexer utiliser read.php buildIdx.
  Une boite est indexée permet de:
    - d'afficher le nombre de messages,
    - se positionner efficacement notamment vers la fin de la bal.
journal: |
  27/3/2020:
    - correction bug
    - ajout action==info et utilisation du composant Yaml de Symfony
  21/3/2020:
    - téléchargement d'une pièce jointe d'un message
    - utilisation du fichier index s'il existe ; il peut être créé par read.php
  20/3/2020:
    - gestion de différents fichiers Mbox
    - déplacement de ttes les boites aux lettres dans le répertoire mboxes
  19/3/2020:
    - refonte de la gestion des multiparts transférée dans mbox.inc.php
  18/3/2020:
    - initialisation
    - code testé pour les différents formats présents en dehors des multipart
*/
ini_set('max_execution_time', 600);
require_once __DIR__.'/vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

// liste des mbox possibles, la première est par défaut
$mboxes = [
  '0entrant', // messages entrants courants
  'entrant201810-oct-dec',
  'entrant201807-juil-sept',
  'entrant201804-avril-juin',
  'entrant201801-janv-mars',
  'entrant201710-oct-dec',
  'entrant201707-juil-sept',
  'entrant201704-avril-juin',
  'entrant201701-janv-mars',
  'Sent',     // messages sortants courants
  '../listes/ogc.mbox', // boite téléchargée le 27/3/2020
  '../baltest',     // boite de test
  '../baltestNonIdx',     // boite de test non indexée
  //'Sympa',  // copie des messages provenant de Sympa
];

require_once __DIR__.'/mbox.inc.php';

if (!isset($_GET['action'])) { // par défaut liste les messages
  // paramètres: mbox?, start?, max?, From?, To?, Subject?
  $mbox = $_GET['mbox'] ?? $mboxes[0];
  $start = $_GET['start'] ?? 0;
  $max = $_GET['max'] ?? 10;

  echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>list $mbox $start $max</title></head><body>\n";
  
  $mboxSelect = "<select name='mbox'>";
  foreach ($mboxes as $pmbox)
    $mboxSelect .= "<option value='$pmbox'".($pmbox==$mbox ? ' selected':'').">$pmbox</option>";
  $mboxSelect .= '</select>';
  
  echo "<form><table border=1><tr>\n";
  echo "<td>$mboxSelect</td>\n";
  echo "<td>start<input type='text' name='start' size='4' value='$start'></td>\n";
  echo "<td>max<input type='text' name='max' size='4' value='$max'></td>\n";
  echo "<td>From<input type='text' name='From' value='",isset($_GET['From']) ? htmlentities($_GET['From']) : '',"'></td>\n";
  echo "<td>To<input type='text' name='To' value='",isset($_GET['To']) ? htmlentities($_GET['To']) : '',"'></td>\n";
  echo "<td>Subject<input type='text' name='Subject' value='",
      isset($_GET['Subject']) ? htmlentities($_GET['Subject']) : '',"'></td>\n";
  echo "<td><input type='submit'></td>\n";
  echo "</tr></table></form>\n";

  echo "<table border=1><th>M</th><th>From</th><th>To</th><th>Date</th><th>Subject</th>\n";
  $criteria = [];
  foreach (['From','To','Subject'] as $key)
    if (isset($_GET[$key]) && $_GET[$key])
      $criteria[$key] = $_GET[$key];
  foreach (Message::parseWithIdx(__DIR__.'/mboxes/'.$mbox, $start, $max, $criteria) as $msg) {
    $headers = $msg->short_headers();
    echo "<tr>";
    echo "<td><a href='?action=get&amp;mbox=$mbox&amp;offset=",$msg->offset(),"'>M</a></td>";
    echo "<td>",htmlentities(mb_substr($headers['From'], 0, 40)),"</td>";
    echo "<td>",isset($headers['To']) ? htmlentities(mb_substr($headers['To'], 0, 40)) : '',"</td>";
    echo "<td>",htmlentities($headers['Date']),"</td>";
    echo "<td>",htmlentities($headers['Subject']),"</td>";
    //echo "<td><pre>",json_encode($msg->short_headers(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"</pre></td>";
    echo "</tr>\n";
  }
  echo "</table>\n";
  
  //echo "newStart=$start<br>\n";
  //echo "max=$max<br>\n";
  $params = '';
  foreach ($_GET as $k => $v) {
    if ($k <> 'start')
      $params .= "&amp;$k=".urlencode($v);
  }
  $curStart = $_GET['start'] ?? 0;
  if (!IndexFile::exists(__DIR__.'/mboxes/'.$mbox)) {
    if ($start == -1) { // Fin de la bal atteinte
      echo "<a href='?start=0$params'>&lt;&lt; 0</a>\n";
      echo "Fin de la bal atteinte<br>\n";
    }
    else
      echo "<a href='?start=$start$params'>$start &gt;</a>\n";
  }
  else { // IndexFile exists
    if ($start == -1) { // Fin de la bal atteinte
      echo "<a href='?start=0$params'>&lt;&lt; 0</a>\n";
      $prevStart = $curStart - $max;
      if ($prevStart > 0)
        echo "<a href='?start=$prevStart$params'>&lt; $prevStart</a>\n";
      echo "Fin de la bal atteinte<br>\n";
    }
    else {
      $idxFile = new IndexFile(__DIR__.'/mboxes/'.$mbox);
      $idxSize = $idxFile->size();
      if ($curStart <> 0)
        echo "<a href='?start=0$params'>&lt;&lt; 0</a>\n";
      $prevStart = $curStart - $max;
      if ($prevStart > 0)
        echo "<a href='?start=$prevStart$params'>&lt; $prevStart</a>\n";
      if ($start < $idxSize)
        echo "<a href='?start=$start$params'>$start &gt;</a>\n";
      $last = $idxSize - $max;
      echo "<a href='?start=$last$params'>$last &gt;&gt;</a>\n";
      echo " / $idxSize messages<br>\n";
    }
  }
  //echo "<a href='?action=listContentType&amp;mbox=$mbox&amp;max=1000'>listContentType</a><br>\n";
  //echo "<a href='?action=count'>count</a><br>\n";
  die();
}

function showRecipients(string $recipients): string { // affichage des adresses
  //return htmlentities($recipients);
  $html = "<table border=1>\n";
  //$html .= "<th>libelle</th><th>adresse</th>\n";
  foreach (Message::explodeEmails($recipients) as $recipient) {
    if (preg_match('!^(.*)<([-.@a-zA-Z0-9]+)>$!', $recipient, $matches))
      $html .= "<tr><td>".htmlentities($matches[1])."</td><td>$matches[2]</td></tr>\n";
    elseif (preg_match('!^[-.@a-zA-Z0-9]+$!', $recipient, $matches))
      $html .= "<tr><td></td><td>$recipient</td></tr>\n";
    else
      $html .= "<tr><td colspan=2>".htmlentities($recipient)."</td></tr>\n";
  }
  $html .= "</table>\n";
  return $html;
}

if ($_GET['action'] == 'get') { // affiche un message donné défini par son offset 
  // paramètres: action==get, mbox, offset, path?
  if (!isset($_GET['mbox'])) die("Erreur paramètre mbox obligatoire");
  if (!isset($_GET['offset'])) die("Erreur paramètre offset obligatoire");
  echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>get $_GET[mbox] $_GET[offset]</title></head><body>\n";
  $msg = Message::get(__DIR__.'/mboxes/'.$_GET['mbox'], $_GET['offset'], isset($_GET['path']) ? explode('/', $_GET['path']) : []);
  echo "<table border=1>\n";
  $headers = $msg->short_headers();
  echo "<tr><td>Date</td><td>",htmlentities($headers['Date']),"</td></tr>\n";
  echo "<tr><td>From</td><td>",htmlentities($headers['From']),"</td></tr>\n";
  //echo "<tr><td><a href='?action=sortEmail&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]'>To</a></td>",
  //     "<td>",htmlentities($headers['To']),"</td></tr>\n";
  if (isset($headers['To']))
    echo "<tr><td><a href='?action=sortEmail&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]&amp;header=To'>To</a></td>",
         "<td>",showRecipients($headers['To']),"</td></tr>\n";
  if (isset($headers['Cc']))
    echo "<tr><td><a href='?action=sortEmail&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]&amp;header=Cc'>Cc</a></td>",
         "<td>",showRecipients($headers['Cc']),"</td></tr>\n";
  echo "<tr><td>Subject</td><td>",htmlentities($headers['Subject']),"</td></tr>\n";
  if (isset($_GET['debug'])) {
    echo "<tr><td>Content-Type</td><td>",htmlentities($headers['Content-Type'] ?? 'Non défini'),"</td></tr>\n";
    if (isset($headers['Content-Transfer-Encoding']) && ($headers['Content-Transfer-Encoding'] <> '8bit'))
      echo "<tr><td>Content-Transfer-Encoding</td><td>",htmlentities($headers['Content-Transfer-Encoding']),"</td></tr>\n";
  }
  echo "<tr><td>Body</td><td>",$msg->body()->asHtml(isset($_GET['debug'])),"</td></tr>\n";
  echo "</table>\n";
  if (!isset($_GET['debug']))
    echo "<a href='?action=get&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]&amp;debug=true'>debug</a>\n";
  echo "<a href='?action=dump&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]'>dump</a><br>\n";
  die();
}

// ajoute l'élément $elt à $array[$key1][$key2]
function addEltToArray(array &$array, string $key1, string $key2, $elt): void {
  if (!isset($array[$key1][$key2]))
    $array[$key1][$key2] = [ $elt ];
  else {
    $array[$key1][$key2][] = $elt;
  }
}

if ($_GET['action'] == 'sortEmail') { // tri les adresses par nom de domaine et si possible par nom
  // paramètres: action==sortEmail, mbox, offset, header
  $msg = Message::get(__DIR__.'/mboxes/'.$_GET['mbox'], $_GET['offset']);
  echo "<h2>Tri des adresses de $_GET[header] par domaine et si possible par nom</h2>\n";
  echo "Les libellés sont conservés mais un seul par adresse.</p>\n";
  $emails = []; // [ domain => [ email => [ label ] ] ]
  foreach (Message::explodeEmails($msg->short_headers()[$_GET['header']]) as $recipient) {
    if (preg_match('!^(.*)<([-.a-zA-Z0-9]+)@([-.a-zA-Z0-9]+)>$!', $recipient, $matches)) {
      $label = $matches[1];
      $name = $matches[2];
      $domain = $matches[3];
      if (preg_match('!^([-.a-zA-Z0-9]+)\.(.*)$!', $name, $matches))
        $key2 = "$matches[2].$matches[1]";
      else
        $key2 = $name;
      addEltToArray($emails, $domain, strToLower($key2), ['label'=> $label, 'email'=> "$name@$domain"]);
    }
    elseif (preg_match('!^([-.a-zA-Z0-9]+)@([-.a-zA-Z0-9]+)$!', $recipient, $matches)) {
      $label = '';
      $name = $matches[1];
      $domain = $matches[2];
      if (preg_match('!^([-.a-zA-Z0-9]+)\.(.*)$!', $name, $matches))
        $key2 = "$matches[2].$matches[1]";
      else
        $key2 = $name;
      addEltToArray($emails, $domain, strToLower($key2), ['label'=> $label, 'email'=> "$name@$domain"]);
    }
    else {
      addEltToArray($emails, ' domaine non défini', $recipient, ['label'=> '', 'email'=> $recipient]);
    }
  }
  ksort($emails);
  foreach ($emails as $domain => &$recipients)
    ksort($recipients);
  //echo "<pre>"; print_r($emails); echo "</pre>\n"; //die();
  // ATTENTION: si je réutilise la variable $recipients alors cela génère un bug
  foreach ($emails as $domain => $recipients2) {
    echo "<h4>$domain</h4>\n";
    //print_r($recipients2); echo "<br>\n";
    foreach ($recipients2 as $recipient) {
      //print_r($recipient[0]);
      echo htmlentities($recipient[0]['label']),"&lt;",$recipient[0]['email'],"&gt;<br>\n";
    }
  }
  //echo "<pre>"; print_r($emails);
  
  echo "<h2>Chaine initiale</h2>\n",
       htmlentities($msg->short_headers()[$_GET['header']]),"</p>\n";
  die();
}

if ($_GET['action'] == 'dlAttached') { // téléchargement d'une pièce jointe d'un message définie par path
  //paramètres: action==dlAttached, mbox, offset, path, debug?
  $msg = Message::get(__DIR__.'/mboxes/'.$_GET['mbox'], $_GET['offset']);
  $msg->dlAttached(explode('/', $_GET['path']), isset($_GET['debug']));
  die();
}

if ($_GET['action'] == 'dump') { // dump du message défini par son offset 
  // paramètres: action==dump, mbox, offset
  $msg = Message::get(__DIR__.'/mboxes/'.$_GET['mbox'], $_GET['offset']);
  echo "<pre>"; print_r($msg);
  die();
}

if ($_GET['action'] == 'info') { // affichage du fichier info.yaml
  $info = file_get_contents('info.yaml');
  $info = Yaml::parse($info);
  header('Content-type: application/json');
  echo json_encode($info, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
  die();
}

if ($_GET['action'] == 'listContentType') { // liste les Content-Type contenu dans les messages et leur fréquence 
  //echo "<pre>";
  $mbox = $_GET['mbox'] ?? $mboxes[0];
  $start = $_GET['start'] ?? 0;
  $max = $_GET['max'] ?? 10;
  $contentTypes = [];
  foreach (Message::parse(__DIR__.'/mboxes/'.$mbox, $start, $max) as $msg) {
    $contentType = $msg->short_headers()['Content-Type'] ?? '';
    //echo "$contentType\n";
    if (preg_match('!^(.*boundary=")[^"]*(".*)$!', $contentType, $matches))
      $contentType = "$matches[1]---$matches[2]";
    elseif (preg_match('!^(.*boundary=)[-_.0-9a-zA-Z]*$!', $contentType, $matches))
      $contentType = "$matches[1]---";
    if (!isset($contentTypes[$contentType]))
      $contentTypes[$contentType] = 1;
    else
      $contentTypes[$contentType]++;
  }
  foreach ($contentTypes as $contentType => $nbre) {
    $href = "?action=searchByContentType&amp;mbox=$mbox&amp;contentType=".urlencode($contentType)."&amp;max=$max";
    echo "<a href='$href'>$contentType ($nbre)</a><br>\n";
  }
  die();
}

if ($_GET['action'] == 'searchByContentType') {
  $mbox = $_GET['mbox'];
  $sContentType = $_GET['contentType'];
  if (substr($sContentType, 0, 10) == 'multipart/') {
    $sContentType = str_replace('---', '.*', $sContentType);
  }
  $start = 0;
  echo "searchByContentType <b>$sContentType</b><br>\n";
  echo "<table border=1><th>G</th><th>From</th><th>Date</th><th>Subject</th>\n";
  foreach (Message::parse(__DIR__.'/mboxes/'.$mbox, $start, $_GET['max'] ?? 10) as $msg) {
    $headers = $msg->short_headers();
    $contentType = $headers['Content-Type'] ?? '';
    if (substr($sContentType, 0, 10) == 'multipart/') {
      if (!preg_match("!^$sContentType$!", $contentType)) continue;
    }
    else {
      if ($contentType <> $sContentType) continue;
    }
    echo "<tr>";
    echo "<td><a href='?action=get&amp;mbox=$mbox&amp;offset=",$msg->offset(),"'>G</a></td>";
    echo "<td>",htmlentities($headers['From']),"</td>";
    echo "<td>",htmlentities($headers['Date']),"</td>";
    echo "<td>",htmlentities($headers['Subject']),"</td>";
    //echo "<td><pre>",json_encode($msg->short_headers(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"</pre></td>";
    echo "</tr>\n";
  }
  echo "</table>\n";
  die();
}

// 15128 messages dans 0entrant
if ($_GET['action'] == 'count') {
  $start = 0;
  $nbre = 0;
  foreach (Message::parse(__DIR__.'/mboxes/'.$mboxes[0], $start, 1000000) as $msg) {
    $nbre++;
  }
  echo "$nbre messages dans $mboxes[0]<br>\n";
  die();
}

die("Aucun traitement reconnu pour $_GET[action]\n");
