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
    - connaitre le nombre de messages,
    - se positionner efficacement vers la fin de la bal.
journal: |
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

// liste des mbox possibles, la première est par défaut
$mboxes = [
  '0entrant', // messages entrants courants
  'entrant201801-janv-mars',
  'entrant201804-avril-juin',
  'entrant201807-juil-sept',
  'entrant201810-oct-dec',
  'Sent',     // messages sortants courants
  'test',     // boite de test
  'testNonIdx',     // boite de test non indexée
  //'Sympa',  // copie des messages provenant de Sympa
];

require_once __DIR__.'/mbox.inc.php';

if (!isset($_GET['action'])) { // par défaut liste les messages
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
    if (isset($_GET[$key]))
      $criteria[$key] = $_GET[$key];
  foreach (Message::parseWithIdx(__DIR__.'/mboxes/'.$mbox, $start, $max, $criteria) as $msg) {
    $header = $msg->short_header();
    echo "<tr>";
    echo "<td><a href='?action=get&amp;mbox=$mbox&amp;offset=",$msg->offset(),"'>M</a></td>";
    echo "<td>",htmlentities(mb_substr($header['From'], 0, 40)),"</td>";
    echo "<td>",htmlentities(mb_substr($header['To'], 0, 40)),"</td>";
    echo "<td>",htmlentities($header['Date']),"</td>";
    echo "<td>",htmlentities($header['Subject']),"</td>";
    //echo "<td><pre>",json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"</pre></td>";
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

// une , est un séparateur d'adresse que si elle n'est pas à l'intérieur d'une ""
function explodeListEmails(string $recipients): array {
  //return explode(',', $recipients);
  $pattern = '!^ *("[^"]*")?([^,]*),!';
  $list = [];
  while(preg_match($pattern, $recipients, $matches)) {
    $list[] = $matches[1].$matches[2];
    //echo "<pre>matches="; print_r($matches); echo "</pre>\n";
    $recipients = preg_replace($pattern, '', $recipients);
    // (count($list) > 100) die("FIN");
  }
  $list[] = $recipients;
  //echo "<pre>list="; print_r($list); echo "</pre>\n";
  return $list;
}

function showRecipients(string $recipients): string { // affichage des adresses
  //return htmlentities($recipients);
  $html = "<table border=1>\n";
  //$html .= "<th>libelle</th><th>adresse</th>\n";
  foreach (explodeListEmails($recipients) as $recipient) {
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
  echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>get $_GET[mbox] $_GET[offset]</title></head><body>\n";
  $msg = Message::get(__DIR__.'/mboxes/'.$_GET['mbox'], $_GET['offset']);
  echo "<table border=1>\n";
  $header = $msg->short_header();
  echo "<tr><td>Date</td><td>",htmlentities($header['Date']),"</td></tr>\n";
  echo "<tr><td>From</td><td>",htmlentities($header['From']),"</td></tr>\n";
  //echo "<tr><td><a href='?action=sortEmail&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]'>To</a></td>",
  //     "<td>",htmlentities($header['To']),"</td></tr>\n";
  echo "<tr><td><a href='?action=sortEmail&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]&amp;header=To'>To</a></td>",
       "<td>",showRecipients($header['To']),"</td></tr>\n";
  if (isset($header['Cc']))
    echo "<tr><td><a href='?action=sortEmail&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]&amp;header=Cc'>Cc</a></td>",
         "<td>",showRecipients($header['Cc']),"</td></tr>\n";
  echo "<tr><td>Subject</td><td>",htmlentities($header['Subject']),"</td></tr>\n";
  echo "<tr><td>Content-Type</td><td>",htmlentities($header['Content-Type'] ?? 'Non défini'),"</td></tr>\n";
  if (isset($header['Content-Transfer-Encoding']) && ($header['Content-Transfer-Encoding'] <> '8bit'))
    echo "<tr><td>Content-Transfer-Encoding</td><td>",htmlentities($header['Content-Transfer-Encoding']),"</td></tr>\n";
  echo "<tr><td>Body</td><td>",$msg->body()->asHtml(isset($_GET['debug'])),"</td></tr>\n";
  echo "</table>\n";
  echo "<a href='?action=dump&amp;mbox=$_GET[mbox]&amp;offset=$_GET[offset]'>dump</a><br>\n";
  die();
}

// ajoute l'élément $elt au champ $key de $array
function addEltToArray(array &$array, string $key1, string $key2, $elt): void {
  if (!isset($array[$key1][$key2]))
    $array[$key1][$key2] = [ $elt ];
  else {
    $array[$key1][$key2][] = $elt;
  }
}

if ($_GET['action'] == 'sortEmail') { // tri les adresses par nom de domaine et si possible par nom
  $msg = Message::get(__DIR__.'/mboxes/'.$_GET['mbox'], $_GET['offset']);
  echo "<h2>Tri des adresses de $_GET[header] par domaine et si possible par nom</h2>\n";
  echo "Les libellés sont conservés mais un seul par adresse.</p>\n";
  $emails = []; // [ domain => [ email => [ label ] ] ]
  foreach (explodeListEmails($msg->short_header()[$_GET['header']]) as $recipient) {
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
       htmlentities($msg->short_header()[$_GET['header']]),"</p>\n";
  die();
}

if ($_GET['action'] == 'dlAttached') { // téléchargement d'une pièce jointe d'un message
  $msg = Message::get(__DIR__.'/mboxes/'.$_GET['mbox'], $_GET['offset']);
  $msg->dlAttached($_GET['name'], isset($_GET['debug']));
  die();
}

if ($_GET['action'] == 'dump') { // dump du message défini par son offset 
  $msg = Message::get(__DIR__.'/mboxes/'.$_GET['mbox'], $_GET['offset']);
  echo "<pre>"; print_r($msg);
  die();
}


if ($_GET['action'] == 'listContentType') { // liste les Content-Type contenu dans les messages et leur fréquence 
  //echo "<pre>";
  $mbox = $_GET['mbox'] ?? $mboxes[0];
  $start = $_GET['start'] ?? 0;
  $max = $_GET['max'] ?? 10;
  $contentTypes = [];
  foreach (Message::parse(__DIR__.'/mboxes/'.$mbox, $start, $max) as $msg) {
    $contentType = $msg->short_header()['Content-Type'] ?? '';
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
    $header = $msg->short_header();
    $contentType = $header['Content-Type'] ?? '';
    if (substr($sContentType, 0, 10) == 'multipart/') {
      if (!preg_match("!^$sContentType$!", $contentType)) continue;
    }
    else {
      if ($contentType <> $sContentType) continue;
    }
    echo "<tr>";
    echo "<td><a href='?action=get&amp;mbox=$mbox&amp;offset=",$msg->offset(),"'>G</a></td>";
    echo "<td>",htmlentities($header['From']),"</td>";
    echo "<td>",htmlentities($header['Date']),"</td>";
    echo "<td>",htmlentities($header['Subject']),"</td>";
    //echo "<td><pre>",json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"</pre></td>";
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
