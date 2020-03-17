<?php
// rmbox/index.php - lecture d'un fichier mbox (https://fr.wikipedia.org/wiki/Mbox)
// Affichage des en-tetes

$path = '0entrant';

require_once __DIR__.'/mbox.inc.php';

$start = $_GET['start'] ?? 0;
$max = $_GET['max'] ?? 10;

echo "<form><table border=1><tr>\n";
echo "<td>start<input type='text' name='start' size='4' value='$start'></td>\n";
echo "<td>max<input type='text' name='max' size='4' value='$max'></td>\n";
echo "<td>From<input type='text' name='From' value='",$_GET['From'] ?? '',"'></td>\n";
echo "<td><input type='submit'></td>\n";
echo "</tr></table></form>\n";

echo "<table border=1><th>From</th><th>Date</th><th>Subject</th>\n";
$criteria = [];
if (isset($_GET['From']))
  $criteria['From'] = $_GET['From'];
foreach (Message::parse($path, $criteria, $start, $max) as $msg) {
  echo "<tr>";
  echo "<td>",htmlentities($msg->short_header()['From'][0]),"</td>";
  echo "<td>",htmlentities($msg->short_header()['Date'][0]),"</td>";
  echo "<td>",htmlentities($msg->short_header()['Subject'][0]),"</td>";
  //echo "<td><pre>",json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"</pre></td>";
  echo "</tr>\n";
}
echo "</table>\n";
echo "nextStart=$start<br>\n";
echo "<td><a href='?start=",$start,"&amp;max=$max'>&gt;</td>\n";
