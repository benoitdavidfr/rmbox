<?php
// graph.php - génère en sortie un fichier avec une ligne par adresse dans un mel en entrée et un poids associé sépars par un ;

require_once __DIR__.'/mbox.inc.php';


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


if (0) { // nbre de mails recus depuis l'adresse
  $mbox = '0entrant';
  $path = __DIR__.'/mboxes/'.$mbox;

  $emails = []; // [ email => count ]
  $start = 0;
  foreach (Message::parse($path, $start, 999999, []) as $msg) {
    $headers = $msg->short_header();
    if (!isset($headers['From'])) {
      //echo json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
      continue;
    }
    $email = strToLower(clean_email($headers['From']));
    if (!isset($emails[$email]))
      $emails[$email] = 1;
    else
      $emails[$email]++;
  }
  asort($emails);
  foreach($emails as $email => $count)
    echo "$email $count\n";
  die();
}

{/* nbre de mails recus depuis l'adresse
information@gouvernement.fr 50
olivier.garry@developpement-durable.gouv.fr 50
sandrine.robert@agriculture.gouv.fr 51
pierre.terrier@developpement-durable.gouv.fr 51
cfdt-ufe@i-carre.net 51
rosemarie.meyberg@developpement-durable.gouv.fr 52
evelyne.leblond@developpement-durable.gouv.fr 53
support@newsletter.decryptageo.fr 54
philippe.dornoy@developpement-durable.gouv.fr 55
nicolas.lambert@ign.fr 57
chegot@cgt.fr 57
diffusion.force-ouvriere@feets-fo.fr 58
revue-ein@prodigital.fr 60
edwige.duclay@developpement-durable.gouv.fr 60
cyril.aeck@developpement-durable.gouv.fr 60
jean-philippe.lang@developpement-durable.gouv.fr 62
arnaud.tichit@developpement-durable.gouv.fr 63
christophe.badol@cerema.fr 64
alain.griot@developpement-durable.gouv.fr 64
noreply@jamespot.pro 76
noreply@ima-dt.org 79
no-reply@framasoft.org 81
claire.sallenave@developpement-durable.gouv.fr 93
ghizlane.lebelle@developpement-durable.gouv.fr 93
laetitia.el-beze@developpement-durable.gouv.fr 95
pierre.vergez@ign.fr 117
formation.ag1.sdag.cgdd@developpement-durable.gouv.fr 129
crdd@developpement-durable.gouv.fr 136
jose.devers@developpement-durable.gouv.fr 137
frederique.millard@developpement-durable.gouv.fr 146
no-reply@data.gouv.fr 149
asce-ac.as.oh@i-carre.net 158
luc.mathis@developpement-durable.gouv.fr 174
sylvain.pradelle@developpement-durable.gouv.fr 179
helene.barthelemy@developpement-durable.gouv.fr 182
lionel.janin@developpement-durable.gouv.fr 184
loic.gourmelen@cerema.fr 185
rosa.casany@developpement-durable.gouv.fr 241
hugues.cahen@developpement-durable.gouv.fr 248
benoit.spittler@developpement-durable.gouv.fr 256
yannis.imbert@developpement-durable.gouv.fr 271
clement.jaquemet@developpement-durable.gouv.fr 275
benoit.david@developpement-durable.gouv.fr 346
thierry.courtine@developpement-durable.gouv.fr 448
marc.leobet@developpement-durable.gouv.fr 664
helene.costa@developpement-durable.gouv.fr 704
oriane.gauffre@developpement-durable.gouv.fr 778
olivier.dissard@developpement-durable.gouv.fr 1447
*/}

if (1) { // nbre de mails envoyés à l'adresse
  $mbox = 'Sent';
  $path = __DIR__.'/mboxes/'.$mbox;

  $emails = []; // [ email => count ]
  $start = 0;
  //foreach (Message::parse($path, $start, 999999, []) as $msg) {
  foreach (Message::parse($path, $start, 999999, []) as $msg) {
    $headers = $msg->short_header();
    if (!isset($headers['To'])) {
      //echo json_encode($msg->short_header(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n\n";
      continue;
    }
    $recipients = $headers['To'].(isset($headers['Cc']) ? ','.$headers['Cc'] : '');
    //if (!preg_match('!jaquemet!', $recipients)) continue;
    //echo "recipients: $recipients\n";
    //echo "list:\n";
    foreach (Message::explodeEmails($recipients) as $recipient) {
      $email = strToLower(Message::clean_email($recipient));
      //echo "  - $email\n";
      if (!isset($emails[$email]))
        $emails[$email] = 1;
      else
        $emails[$email]++;
    }
  }
  asort($emails);
  //echo "------------------------------\n";
  foreach($emails as $email => $count)
    echo "$email $count\n";
  die();
}

{/* Nbre de mail envoyés
frederique.janvier@developpement-durable.gouv.fr 50
veronique.pereira@ign.fr 50
frederique.couderette@i-carre.net 50
t.vilmus@brgm.fr 51
richard.mitanchey@cerema.fr 51
patrick.sillard@developpement-durable.gouv.fr 52
pascal.lory@developpement-durable.gouv.fr 54
prm@ign.fr 55
pascal.douard@developpement-durable.gouv.fr 56
vincent.pircher@developpement-durable.gouv.fr 58
michel.segard@ign.fr 62
jean-luc.cousin@ign.fr 65
loic.gourmelen@cerema.fr 67
philippe.dornoy@developpement-durable.gouv.fr 68
aurelie.vieillefosse@developpement-durable.gouv.fr 69
ghizlane.lebelle@developpement-durable.gouv.fr 70
valery.morard@developpement-durable.gouv.fr 72
sophie.reynard@ign.fr 73
pierre.vergez@ign.fr 73
alain.griot@developpement-durable.gouv.fr 74
olivier.garry@developpement-durable.gouv.fr 79
astrid.lotito@developpement-durable.gouv.fr 79
martin.bortzmeyer@developpement-durable.gouv.fr 82
chantal.bienaime@developpement-durable.gouv.fr 83
nicolas.lambert@ign.fr 86
claude.penicand@ign.fr 92
laetitia.el-beze@developpement-durable.gouv.fr 94
fionn.halleman@ign.fr 96
p.lagarde@brgm.fr 99
charles-guillaume.blanchon@developpement-durable.gouv.fr 102
serge.doba@developpement-durable.gouv.fr 105
sylvain.pradelle@developpement-durable.gouv.fr 107
jean-philippe.lang@developpement-durable.gouv.fr 108
marie-christine.combes-miakinen@ign.fr 116
jerome.desboeufs@data.gouv.fr 124
bernard.allouche@cerema.fr 125
arnaud.tichit@developpement-durable.gouv.fr 125
j.nguyen@developpement-durable.gouv.fr 131
frederique.millard@developpement-durable.gouv.fr 139
mel@benoit.david.name 153
agents.mig.dri.cgdd@developpement-durable.gouv.fr 156
benoit.david@developpement-durable.gouv.fr 160
jp.torterotot@developpement-durable.gouv.fr 169
lionel.janin@developpement-durable.gouv.fr 206
hugues.cahen@developpement-durable.gouv.fr 218
samuel.goldszmidt@developpement-durable.gouv.fr 232
celine.bonhomme@developpement-durable.gouv.fr 238
benoit.spittler@developpement-durable.gouv.fr 279
hugo.berthele@developpement-durable.gouv.fr 285
jaquemet 327
michel.frances@developpement-durable.gouv.fr 348
stephane.trainel@developpement-durable.gouv.fr 373
laurent.belanger@developpement-durable.gouv.fr 383
helene.costa@developpement-durable.gouv.fr 523
helene.barthelemy@developpement-durable.gouv.fr 530
serge.bossini@developpement-durable.gouv.fr 541
claire.sallenave@developpement-durable.gouv.fr 569
jose.devers@developpement-durable.gouv.fr 576
yannis.imbert@developpement-durable.gouv.fr 585
luc.mathis@developpement-durable.gouv.fr 691
thierry.courtine@developpement-durable.gouv.fr 955
marc.leobet@developpement-durable.gouv.fr 980
clement.jaquemet@developpement-durable.gouv.fr 1026
oriane.gauffre@developpement-durable.gouv.fr 1118
olivier.dissard@developpement-durable.gouv.fr 2664
*/}

