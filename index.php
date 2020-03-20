<?php
/*PhpDoc:
name: index.php
title: index.php - navigation dans un fichier Mbox (https://fr.wikipedia.org/wiki/Mbox)
doc: |
  - liste des messages respectant certains critères
  - affichage d'un message particulier avec notamment accès aux pièces-jointes
  - affichage de la liste des Content-Type et des messages correspondants à chacun
journal: |
  20/3/2020:
    - gestion de différents fichiers Mbox
  19/3/2020:
    - refonte de la gestion des multiparts transférée dans mbox.inc.php
  18/3/2020:
    - initialisation
    - code testé pour les différents formats présents en dehors des multipart
*/
ini_set('max_execution_time', 600);
$mboxes = ['0entrant', 'Sent']; // liste des mbox possibles

require_once __DIR__.'/mbox.inc.php';

if (!isset($_GET['action'])) { // par défaut liste les messages
  $mbox = $_GET['mbox'] ?? $mboxes[0];
  $start = $_GET['start'] ?? 0;
  $max = $_GET['max'] ?? 10;

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

  echo "<table border=1><th>G</th><th>From</th><th>To</th><th>Date</th><th>Subject</th>\n";
  $criteria = [];
  foreach (['From','To','Subject'] as $key)
    if (isset($_GET[$key]))
      $criteria[$key] = $_GET[$key];
  foreach (Message::parse($mbox, $start, $max, $criteria) as $msg) {
    $header = $msg->short_header();
    echo "<tr>";
    echo "<td><a href='?action=get&amp;mbox=$mbox&amp;offset=",$msg->offset(),"'>G</a></td>";
    echo "<td>",htmlentities(mb_substr($header['From'], 0, 40)),"</td>";
    echo "<td>",htmlentities(mb_substr($header['To'], 0, 40)),"</td>";
    echo "<td>",htmlentities($header['Date']),"</td>";
    echo "<td>",htmlentities($header['Subject']),"</td>";
    //echo "<td><pre>",json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"</pre></td>";
    echo "</tr>\n";
  }
  echo "</table>\n";
  if ($start <> -1) {
    echo "nextStart=$start<br>\n";
    echo "<td><a href='?start=$start";
    foreach ($_GET as $k => $v) {
      if ($k <> 'start')
        echo "&amp;$k=",urlencode($v);
    }
    echo "'>$start &gt;<br>\n";
  }
  echo "<a href='?action=listContentType&amp;mbox=$mbox&amp;max=1000'>listContentType</a><br>\n";
  echo "<a href='?action=count'>count</a><br>\n";
  die();
}

if ($_GET['action'] == 'get') { // affiche un message donné défini par son offset 
  $msg = Message::get($_GET['mbox'], $_GET['offset']);
  echo "<table border=1>\n";
  $header = $msg->short_header();
  echo "<tr><td>Date</td><td>",htmlentities($header['Date']),"</td></tr>\n";
  echo "<tr><td>From</td><td>",htmlentities($header['From']),"</td></tr>\n";
  echo "<tr><td>To</td><td>",htmlentities($header['To']),"</td></tr>\n";
  echo "<tr><td>Subject</td><td>",htmlentities($header['Subject']),"</td></tr>\n";
  echo "<tr><td>Content-Type</td><td>",htmlentities($header['Content-Type'] ?? 'Non défini'),"</td></tr>\n";
  if (isset($header['Content-Transfer-Encoding']) && ($header['Content-Transfer-Encoding'] <> '8bit'))
    echo "<tr><td>Content-Transfer-Encoding</td><td>",htmlentities($header['Content-Transfer-Encoding']),"</td></tr>\n";
  echo "<tr><td>Body</td><td>",$msg->body()->asHtml(),"</td></tr>\n";
  echo "</table>\n";
  echo "<a href='?action=dump&amp;offset=$_GET[offset]'>dump</a><br>\n";
  die();
}

if ($_GET['action'] == 'dlAttached') {
  $msg = Message::get($_GET['mbox'], $_GET['offset']);
  $msg->dlAttached($_GET['name']);
  die();
}

if ($_GET['action'] == 'dump') { // dump un message défini par son offset 
  $msg = Message::get($_GET['mbox'], $_GET['offset']);
  echo "<pre>"; print_r($msg);
  die();
}


if ($_GET['action'] == 'listContentType') { // liste les Content-Type contenu dans les messages et leur fréquence 
  //echo "<pre>";
  $mbox = $_GET['mbox'] ?? $mboxes[0];
  $start = $_GET['start'] ?? 0;
  $max = $_GET['max'] ?? 10;
  $contentTypes = [];
  foreach (Message::parse($mbox, $start, $max) as $msg) {
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
  foreach (Message::parse($mbox, $start, $_GET['max'] ?? 10) as $msg) {
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

// 15164 messages
if ($_GET['action'] == 'count') {
  $start = 0;
  $nbre = 0;
  foreach (Message::parse($mboxes[0], $start, 1000000) as $msg) {
    $nbre++;
  }
  echo "$nbre messages dans $mboxes[0]<br>\n";
  die();
}

die("Aucun traitement reconnu pour $_GET[action]\n");
