<?php
/*PhpDoc:
name: index.php
title: index.php - navigation dans un fichier Mbox (https://fr.wikipedia.org/wiki/Mbox)
doc: |
  - liste des messages respectant certains critères
  - affichage d'un message particulier avec notamment accès aux pièces-jointes
  - affichage de la liste des Content-Type et des messages correspondants à chacun
journal: |
  18/3/2020:
    initialisation
*/
ini_set('max_execution_time', 600);
$path = '0entrant';

require_once __DIR__.'/mbox.inc.php';

if (!isset($_GET['action'])) { // par défaut liste les messages
  $start = $_GET['start'] ?? 0;
  $max = $_GET['max'] ?? 10;

  echo "<form><table border=1><tr>\n";
  echo "<td>start<input type='text' name='start' size='4' value='$start'></td>\n";
  echo "<td>max<input type='text' name='max' size='4' value='$max'></td>\n";
  echo "<td>From<input type='text' name='From' value='",isset($_GET['From']) ? htmlentities($_GET['From']) : '',"'></td>\n";
  echo "<td>Subject<input type='text' name='Subject' value='",
      isset($_GET['Subject']) ? htmlentities($_GET['Subject']) : '',"'></td>\n";
  echo "<td><input type='submit'></td>\n";
  echo "</tr></table></form>\n";

  echo "<table border=1><th>G</th><th>From</th><th>Date</th><th>Subject</th>\n";
  $criteria = [];
  foreach (['From','Subject'] as $key)
    if (isset($_GET[$key]))
      $criteria[$key] = $_GET[$key];
  foreach (Message::parse($path, $start, $max, $criteria) as $msg) {
    $header = $msg->short_header();
    echo "<tr>";
    echo "<td><a href='?action=get&amp;offset=",$msg->offset(),"'>G</a></td>";
    echo "<td>",htmlentities($header['From']),"</td>";
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
  echo "<a href='?action=listContentType&max=1000'>listContentType</a><br>\n";
  die();
}

// découpe le body en fonction du boundary
function multipart(string $body, string $boundary): array {
  //echo "<pre>",$body,"</pre>\n","boundary=$boundary<br>\n";
  $text = explode("\n", $body);
  $parts = [];
  $part = [];
  foreach ($text as $line) {
    if (strpos($line, $boundary) !== FALSE) {
      $parts[] = $part;
      $part = [];
    }
    else {
      $part[] = $line;
    }
  }
  $parts[] = $part;
  //echo "<pre>parts="; print_r($parts); echo "</pre>\n";
  array_shift($parts);
  return $parts;
}

// affiche une partie structurée avec un en-tête contenant un Content-Type
function showPart(array $part): string {
  $headers = [];
  while($line = array_shift($part)) {
    $pos = strpos($line, ': ');
    $headers[substr($line, 0, $pos)] = substr($line, $pos+2);
  }
  //echo "<pre>headers="; print_r($headers); echo "</pre>\n";
  if (!$headers) {
    return '';
  }
  elseif (preg_match('!^text/plain; charset=(utf|UTF)-8!', $headers['Content-Type'])) {
    return "<pre>".implode("\n", $part)."</pre>";
  }
  elseif (preg_match('!^text/html; charset=(utf|UTF)-8!', $headers['Content-Type'])) {
    return implode("\n", $part);
  }
  elseif ($headers['Content-Type'] == 'image/jpeg') {
    return '<img src="data:image/jpeg;base64,'.implode('',$part).'">';
  }
  elseif ($headers['Content-Type'] == 'image/gif') {
    return '<img src="data:image/gif;base64,'.implode('',$part).'">';
  }
  else {
    return "<pre>Content-Type '".$headers['Content-Type']."' inconnu ligne ".__LINE__."\n"
      .implode("\n", $part)."</pre>";
  }
}
// <img src=”data:image/png;base64,iVBORw0KGgoAAAANS… (see source for full base64 encoded image) …8bgAAAAASUVORK5CYII=”>

// affiche le body en fonction du $contentType
function showBody(string $body, string $contentType=''): string {
  if (preg_match('!^text/plain; charset="?([-a-zA-Z0-9]*)!', $contentType, $matches)) {
    $charset = $matches[1];
    if (!in_array($charset, ['utf-8','UTF-8']))
      $body = mb_convert_encoding($body, 'utf-8', $charset);
    return '<pre>'.htmlentities($body).'</pre>';
  }
  
  if (preg_match('!^multipart/(alternative|related); boundary="([^"]*)"!', $contentType, $matches)) {
    $boundary = $matches[2];
    $newBody = '';
    foreach (multipart($body(), $boundary) as $part) {
      $newBody .= '<tr><td>'.showPart($part)."</td></tr>\n";
    }
    return '<table border=1>'.$newBody."</table>\n";
  }
  
  return "<b>Unknown Content-Type '$contentType'</b>"
    .'<pre>'.htmlentities($body).'</pre>';
}

if ($_GET['action'] == 'get') { // affiche un message donné défini par son offset 
  $msg = Message::get($path, $_GET['offset']);
  echo "<table border=1>\n";
  $header = $msg->short_header();
  echo "<tr><td>Date</td><td>",htmlentities($header['Date']),"</td></tr>\n";
  echo "<tr><td>From</td><td>",htmlentities($header['From']),"</td></tr>\n";
  echo "<tr><td>To</td><td>",htmlentities($header['To']),"</td></tr>\n";
  echo "<tr><td>Subject</td><td>",htmlentities($header['Subject']),"</td></tr>\n";
  echo "<tr><td>Content-Type</td><td>",htmlentities($header['Content-Type']),"</td></tr>\n";
  if (isset($header['Content-Transfer-Encoding']) && ($header['Content-Transfer-Encoding'] <> '8bit'))
    echo "<tr><td>Content-Transfer-Encoding</td><td>",htmlentities($header['Content-Transfer-Encoding']),"</td></tr>\n";
  echo "<tr><td>Body</td><td>",showBody($msg->body(), $header['Content-Type']),"</td></tr>\n";
  echo "</table>\n";
  echo "<a href='?action=dump&amp;offset=$_GET[offset]'>dump</a><br>\n";
  die();
}

if ($_GET['action'] == 'dump') { // dump un message défini par son offset 
  $msg = Message::get($path, $_GET['offset']);
  echo "<pre>"; print_r($msg);
  die();
}


if ($_GET['action'] == 'listContentType') { // liste les Content-Type contenu dans les messages et leur fréquence 
  //echo "<pre>";
  $start = $_GET['start'] ?? 0;
  $max = $_GET['max'] ?? 10;
  $contentTypes = [];
  foreach (Message::parse($path, $start, $max) as $msg) {
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
    $href = "?action=searchByContentType&amp;contentType=".urlencode($contentType)."&amp;max=$max";
    echo "<a href='$href'>$contentType ($nbre)</a><br>\n";
  }
  die();
}

if ($_GET['action'] == 'searchByContentType') {
  $sContentType = $_GET['contentType'];
  $start = 0;
  echo "searchByContentType <b>$sContentType</b><br>\n";
  echo "<table border=1><th>G</th><th>From</th><th>Date</th><th>Subject</th>\n";
  foreach (Message::parse($path, $start, $_GET['max'] ?? 10) as $msg) {
    $header = $msg->short_header();
    $contentType = $header['Content-Type'] ?? '';
    if ($contentType <> $sContentType) continue;
    echo "<tr>";
    echo "<td><a href='?action=get&amp;offset=",$msg->offset(),"'>G</a></td>";
    echo "<td>",htmlentities($header['From']),"</td>";
    echo "<td>",htmlentities($header['Date']),"</td>";
    echo "<td>",htmlentities($header['Subject']),"</td>";
    //echo "<td><pre>",json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"</pre></td>";
    echo "</tr>\n";
  }
  echo "</table>\n";
  die();
}

die("Aucun traitement reconnu pour $_GET[action]\n");
