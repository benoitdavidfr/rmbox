<?php
// rmbox/index.php - lecture d'un fichier mbox (https://fr.wikipedia.org/wiki/Mbox)
// Affichage des en-tetes

ini_set('max_execution_time', 600);
$path = '0entrant';

require_once __DIR__.'/mbox.inc.php';

if (!isset($_GET['action'])) {
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

  echo "<table border=1><th>From</th><th>Date</th><th>Subject</th>\n";
  $criteria = [];
  foreach (['From','Subject'] as $key)
    if (isset($_GET[$key]))
      $criteria[$key] = $_GET[$key];
  foreach (Message::parse($path, $start, $max, $criteria) as $msg) {
    $header = $msg->short_header();
    echo "<tr>";
    echo "<td>",htmlentities($header['From'][0]),"</td>";
    echo "<td>",htmlentities($header['Date'][0]),"</td>";
    echo "<td>",htmlentities($header['Subject'][0]),"</td>";
    echo "<td><a href='?action=get&amp;offset=",$msg->offset(),"'>M</a></td>";
    echo "<td><pre>",json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"</pre></td>";
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
    echo "'>&gt;<br>\n";
  }
  die();
}

elseif ($_GET['action'] == 'get') {
  $msg = Message::get($path, $_GET['offset']);
  echo "<pre>"; print_r($msg);
}
