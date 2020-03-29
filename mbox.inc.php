<?php
/*PhpDoc:
name: mbox.inc.php
title: mbox.inc.php - définition de classes pour gérer les messages d'un fichier Mbox
doc: |
  La classe Message gère un message et définit les fonctions d'analyse d'un ficher Mbox pour en extraire un ou plusieurs messages.
  Un message est composé d'en-têtes et d'un corps, géré par la classe abstraite Body ; voir body.inc.php.
  Un corps peut contenir des messages.
  La classe Index gère l'index des messages au sein d'un fichier Mbox.

  message ::= header+ + body
  header ::= content-type | Content-Transfer-Encoding | ...
  body ::= monoPart | multiPart | messageRFC822
  monoPart ::= content-type + Content-Transfer-Encoding + contents
  content-type ::= string
  Content-Transfer-Encoding ::= string
  contents  ::= chaine d'octets
  multiPart ::= mixed | alternative | related | report
  mixed     ::= body + attachment+
  attachment ::= content-type + name + contents | message
  alternative ::= body+
  related  ::= text/html + inlineimage+
  inlineimage ::= content-type + Content-ID + contents
  messageRFC822 ::= message

journal: |
  28/3/2020:
    - correction d'un bug dans Message::body()
    - décomposition du fichier en mbox.inc.php et body.inc.php
    - correction de Message::body() afin que le décodage soit fait dans Body
    - modif principe d'adressage d'une PJ
  27/3/2020:
    - correction d'un bug dans Body::extractHeaders()
    - modification de Body::extractHeaders() et de Message::__construct() pour autoriser dans l'en-tête l'utilisation
      du séparateur ':' avec ou sans blanc après
  23/3/2020:
    - correction d'un bug dans Message::parse()
  22/3/2020:
    - ajout Message::explodeEmails() et Message::cleanEmail()
  21/3/2020:
    - téléchargement d'une pièce jointe d'un message
    - ajout de l'utilisation d'un index pour analyser une bal
    - ajout d'un Body de type message/rfc822 qui correspond à un message inclus dans un autre
  20/3/2020:
    - ajout correction headers erronés
    - détection erreur sur http://localhost/rmbox/?action=get&mbox=Sent&offset=2002264646
      - les messages ajoutés par le calendrier ne sont pas terminées par une ligne vide
      - lors de la lecture le message est agrégé avec le suivant
    - -> mise en place d'un contournement utilisant la méthode Message::isStartOfMessage()
    - ajout d'un parse avec decalage par offset et non par numéro de message
    - ajout gestion format text/calendar et application/ics
  19/3/2020:
    - refonte de la gestion des messages multi-parties avec la hiérarchie de classe Body
    - fonctionne sur http://localhost/rmbox/?action=get&offset=1475300
    - affichage des images en dehors du fichier HTML
  18/3/2020:
    - prise en compte de l'en-tête Content-Transfer-Encoding dans la lecture du corps du message
functions:
classes:
*/

require_once __DIR__.'/body.inc.php';

// utilisation d'un index de la Bal
class IndexFile {
  protected $path; // chemin du fichier index
  protected $file; // descripteur du fichier index
  
  // teste l'existence de l'index
  static function exists(string $path) { return is_file("$path.idx"); }

  function __construct(string $path) {
    $this->path = "$path.idx";
    if (!($this->file = @fopen($this->path, 'r')))
      throw new Exception("Erreur d'ouverture de la mbox $path.idx");
  }

  function __destruct() { if ($this->file) fclose($this->file); }
  
  function size(): int { return filesize($this->path)/21; }
  
  function get(int $start): int {
    if ($start >= $this->size()) {
      $size = $this->size();
      die("Erreur d'accès $start à l'index qui contient $size enregistrements\n");
    }
    fseek($this->file, $start * 21);
    if (!fscanf($this->file, '%20d', $offset)) {
      $size = $this->size();
      die("Erreur d'accès $start à l'index qui contient $size enregistrements\n");
    }
    return $offset;
  }
};


/*PhpDoc: classes
name: Message
title: classe Message - gestion d'un message ainsi que son extraction à partir d'un fichier Mbox
doc: |
  Un message est composé d'en-têtes (header) et d'un corps (body) géré par la classe Body.
  Soit il est contenu dans un fichier Mbox à un certain décalage (offset) par rapport au début du fichier ;
  soit il est inclus dans un autre message, dans ce cas offset==-1 et path indique le chemin à l'intérieur du message
  racine, cad du message stocké dans le fichier Mbox.

  class Message {
    protected $firstLine = ''; // recopie de la première ligne qui constitue le séparateur entre messages dans le fichier Mbox
    protected $headers=[]; // dictionnaire des en-têtes, [ key -> (string | liste(string)) ]
    protected $body; // texte correspondant au corps du message avec séparateur \n entre lignes
    protected $offset; // offset du message dans le fichier mbox, -1 s'il s'agit d'un message inclus dans un autre
    protected $path; // chemin d'accès à partir du message stocké dans le Mbox, s'il est stocké dans le Mbox alors []
methods:
*/
class Message {
  protected $firstLine = ''; // recopie de la première ligne qui constitue le séparateur entre messages dans le fichier Mbox
  protected $headers=[]; // dictionnaire des en-têtes, [ key -> (string | liste(string)) ]
  protected $body; // texte correspondant au corps du message avec séparateur \n entre lignes
  protected $offset; // offset du message dans le fichier mbox, -1 s'il s'agit d'un message inclus dans un autre
  protected $path; // chemin d'accès à partir du message stocké dans le Mbox, s'il est stocké dans le Mbox alors []
  
  // transformation d'une liste d'adresses email sous la forme d'une chaine en une liste de chaines
  // une , est un séparateur d'adresse que si elle n'est pas à l'intérieur d'une ""
  static function explodeEmails(string $recipients): array {
    //return explode(',', $recipients);
    $pattern = '!^ *("[^"]*")?([^,]+),?!';
    $list = [];
    while(preg_match($pattern, $recipients, $matches)) {
      $list[] = $matches[1].$matches[2];
      //echo "<pre>matches="; print_r($matches); echo "</pre>\n";
      $recipients = preg_replace($pattern, '', $recipients);
      //if (count($list) > 100) die("FIN");
    }
    //echo "<pre>list="; print_r($list); echo "</pre>\n";
    return $list;
  }

  static function testExplodeEmails() {
    header('Content-type: text/plain; charset="utf-8"');
    print_r(self::explodeEmails('"jaquemet, Clément " <Clement.Jaquemet@developpement-durable.gouv.fr>'));
  }
  
  // retourne l'adresse brute à partir d'une chaine la contenant
  static function cleanEmail(string $email): string {
    if (preg_match('!^(.*)<([-_.@a-zA-Z0-9]+)>$!', $email, $matches))
      return $matches[2];
    elseif (preg_match('!^ *([-_.@a-zA-Z0-9]+)$!', $email, $matches))
      return $matches[1];
    else
      return $email;
  }
  
  // detection du séparateur entre messages qui marque le début d'un nouveau message avec $line
  // Gestion d'un bug dans le fichier des messages:
  // Lorsque le calendrier insère un message, celui-ci ne se termine pas par une ligne vide mais par une ligne boundary
  static function isStartOfMessage(string $precLine, string $line): bool {
    return (strncmp($line, 'From ', 5)==0)
      && preg_match('!^From (-|[-a-zA-Z0-9\.@]+) [a-zA-Z]{3} [a-zA-Z]{3} \d\d \d\d:\d\d:\d\d \d\d\d\d$!', $line);
  }
  static function isStartOfMessage2(string $precLine, string $line): bool {
    return (strncmp($line, 'From ', 5)==0)
      && (($precLine == '') || (strncmp($precLine, '--Boundary', 10)==0) || (strncmp($precLine, '--------------', 14)==0));
  }
  
  /*PhpDoc: methods
  name: parse
  title: "static function parse(string $path, int &$start, int $maxNbre=10, array $criteria=[]): \\Generator -  retourne les premiers messages respectant les critères"
  doc: |
    Le paramètre $start est retourné avec la valeur à utiliser dans l'appel suivant ou -1 si le fichier a été entièrement parcouru.
    Si $maxNbre vaut -1 alors pas de limire de nbre de messages retournés
    C'est la méthode la plus simple pour parcourir un fichier Mbox mais elle n'est pas très efficace.
    2 solutions pour un parcours plus efficace:
      - si on connait l'offset du message de départ alors utiliser la méthode parseUsingOffset()
      - sinon si un index a été créé alors utiliser parseWithIdx()
      - sinon créer un index et revenir au cas précédent
  */
  static function parse(string $path, int &$start, int $maxNbre=10, array $criteria=[]): \Generator {
    if (!($mbox = @fopen($path, 'r')))
      die("Erreur d'ouverture de mbox $path");
    $precLine = false; // la ligne précédente
    $msgTxt = []; // le message sous la forme d'une liste de lignes rtrimmed
    $no = 0; // le no courant de message
    $offset = 0; // offset de l'entegistrement courant
    while ($iline = fgets($mbox)) {
      $line = rtrim ($iline, "\r\n");
      if (($precLine !== false) && self::isStartOfMessage($precLine, $line)) { // detection d'un nouveau message
        if ($no >= $start) {
          $msg = new Message($msgTxt, $offset, true);
          if ($msg->match($criteria)) {
            yield $msg;
            if ($maxNbre <> -1) {
              if (--$maxNbre <= 0) {
                $start = $no + 1;
                return;
              }
            }
          }
        }
        $offset = ftell($mbox) - strlen($iline); // je mémorise le début du message suivant
        $no++; // no devient celui du message suivant
        //if ($no > 10000) die("fin ligne ".__LINE__);
        $msgTxt = [];
      }
      $msgTxt[] = $line; 
      $precLine = $line;
    }
    $msg = new Message($msgTxt, $offset, true);
    if ($msg->match($criteria)) {
      yield $msg;
    }
    $start = -1;
    return;
  }
  
  /*PhpDoc: methods
  name: parse
  title: "static function parseWithIdx(string $path, int &$start, int $maxNbre=10, array $criteria=[]): \\Generator -  retourne les premiers messages respectant les critères en utilisant l'index s'il existe"
  doc: |
    Fonction similaire à parse(), utilise l'index s'il existe
  */
  static function parseWithIdx(string $path, int &$start, int $maxNbre=10, array $criteria=[]): \Generator {
    if (!IndexFile::exists($path)) {
      return self::parse($path, $start, $maxNbre, $criteria);
    }
    $idx = new IndexFile($path);
    $offset = $idx->get($start); // avec l'index je récupère l'offset du message no start
    return self::parseUsingOffset($path, $offset, $start, $maxNbre, $criteria);
  }
  
  /*PhpDoc: methods
  name: parseUsingOffset
  title: "static function parseUsingOffset(string $path, int &$offset, int &$start, int $maxNbre=10, array $criteria=[]): \\Generator - retourne les premiers messages respectant les critères à partir d'un offset"
  doc: |
    Le paramètre $offset est retourné avec la valeur à utiliser dans l'appel suivant ou -1 si le fichier a été entièrement parcouru
    Le paramètre $start est incrémenté du nombre de messages parcourus, qu'ils soient sélectionnés ou non ;
    il n'est pas utilisé pour sauter des messages.
    Si le paramètre $maxNbre vaut -1 alors sont retournés tous les messages jusqu'à la fin du fichier.
  */
  static function parseUsingOffset(string $path, int &$offset, int &$start, int $maxNbre=10, array $criteria=[]): \Generator {
    if (!($mbox = @fopen($path, 'r')))
      die("Erreur d'ouverture de mbox $path");
    fseek($mbox, $offset);
    $precLine = false; // la ligne précédente
    $msgTxt = []; // le message sous la forme d'une liste de lignes rtrimmed
    while ($iline = fgets($mbox)) {
      $line = rtrim ($iline, "\r\n");
      if (($precLine !== false) && self::isStartOfMessage($precLine, $line)) { // detection d'un nouveau message
        $msg = new Message($msgTxt, $offset, true);
        $start++;
        $offset = ftell($mbox) - strlen($iline); // l'offset du message suivant
        if ($msg->match($criteria)) {
          yield $msg;
          if ($maxNbre <> -1) {
            if (--$maxNbre <= 0) {
              return;
            }
          }
        }
        $msgTxt = [];
      }
      $msgTxt[] = $line; 
      $precLine = $line;
    }
    $msg = new Message($msgTxt, $offset, true);
    if ($msg->match($criteria))
      yield $msg;
    $offset = -1;
    $start = -1;
    return;
  }
  
  /*PhpDoc: methods
  name: get
  title: "static function get(string $path, int $offset, array $partpath=[]): self  - retourne le message défini par les paramètres"
  doc: |
    Si $partpath vaut [] alors retourne le message du fichier $mboxpath commencant à l'offset
    Sinon retourne le message inclus dans le message du fichier $mboxpath commencant à l'offset correspondant au $partpath
  */
  static function get(string $mboxpath, int $offset, array $partpath=[]): self {
    if (!($mbox = @fopen($mboxpath, 'r')))
      throw new Exception("Erreur d'ouverture du fichier mbox $mboxpath");
    fseek($mbox, $offset);
    $precLine = false; // la ligne précédente
    $msgTxt = []; // le message sous la forme d'une liste de lignes rtrimmed
    while ($line = fgets($mbox)) {
      $line = rtrim ($line, "\r\n");
      if (($precLine !== false) && self::isStartOfMessage($precLine, $line)) // détection d'un nouveau message
        break;
      $msgTxt[] = $line; 
      $precLine = $line;
    }
    $msg = new Message($msgTxt, $offset);
    if (!$partpath)
      return $msg;
    else
      return $msg->body()->subMessage($partpath);
  }
    
  /*PhpDoc: methods
  name: __construct
  title: "function __construct(array $txt, int $offset, bool $onlyHeaders=false) - fabrique un objet Message"
  doc: |
    Si $onlyHeaders est mis à true alors l'objet ne contiendra que ses headers permettant ainsi d'économiser de la mémoire.
  */
  function __construct(array $text, int $offset=-1, bool $onlyHeaders=false, array $path=[]) {
    //echo "<pre>Message::__construct()<br>\n";
    $stext = $text; // sauvegarde de $txt
    $this->offset = $offset;
    $this->firstLine = [ array_shift($text) ]; // Suppression de la 1ère ligne d'en-tête qui est aussi le séparateur entre messages
    $this->headers = Body::extractHeaders($text);
    $this->body = $onlyHeaders ? null : implode("\n", $text);
    $this->path = $path;
    //echo "Fin Message::__construct() "; print_r($this); echo "</pre>\n";
  }
  
  /*PhpDoc: methods
  name: body
  title: "function body(): Body - retourne le corps du message comme Body"
  doc: |
  */
  function body(): Body {
    return Body::create(
      $this->headers['Content-Type'] ?? '', // string $type
      $this->body, // string $contents
      isset($this->headers['Content-Transfer-Encoding']) ?
        ['Content-Transfer-Encoding' => $this->headers['Content-Transfer-Encoding']] :
        [], // array $headers
      $this->path // array $path
    );
  }
  
  /*PhpDoc: methods
  name: offset
  title: "function offset(): int - retourne l'offset"
  doc: |
  */
  function offset(): int {
    if ($this->offset == -1)
      throw new Exception("Erreur offset interdit");
    else
      return $this->offset;
  }
  
  /*PhpDoc: methods
  name: short_headers
  title: "function short_headers(): array - retourne un en-tête restreint sous la forme [key -> string]"
  doc: |
    On utilise la liste des keys pour lesquelles headers contient forcément un string et pas un [ string ]
  */
  function short_headers(): array {
    $short = [];
    foreach (Body::SimpleHeaderKeys as $key) {
      if (isset($this->headers[$key]))
        $short[$key] = $this->headers[$key];
    }
    return array_merge($short,['offset'=>$this->offset]);
  }
  
  /*PhpDoc: methods
  name: asArray
  title: "function asArray(): array - retourne le message comme un array exportable en JSON"
  doc: |
  */
  function asArray(): array { return ['header'=> $this->short_headers(), 'body'=> $this->body]; }
  
  function asHtml(bool $debug): string {
    $html = "<table border=1>\n";
    foreach ($this->short_headers() as $key => $value) {
      $html .= "<tr><td>$key</td><td>".htmlentities($value)."</td></tr>\n";
    }
    $html .= "<tr><td>body</td><td>".$this->body()->asHtml($debug)."</td></tr>\n";
    $html .= "</table>\n";
    return $html;
  }
  
  /*PhpDoc: methods
  name: asArray
  title: "function match(array $criteria): bool - teste si la conjonction des critères est vérifiée"
  doc: |
    Les critères sont définis par un array [key -> value] avec:
      - soit key=='Message-ID' et le critère élémentaire est vrai ssi le messsageId du message est identique à value
      - soit key est un des header et le critère élémentaire est vrai ssi ce header est défini et si sa valeur matche
        l'expression régulière définie par value
  */
  function match(array $criteria): bool {
    foreach ($criteria as $key => $cvalue) {
      if ($key == 'Message-ID') {
        if ($this->header['Message-ID'][0] <> $cvalue)
          return false;
      }
      else { // les autres clés
        //echo "test match<br>\n";
        if (!isset($this->headers[$key]))
          return false;
        if (is_string($this->headers[$key])) {
          if (!preg_match("!$cvalue!i", $this->headers[$key]))
            return false;
        }
        else {
          $res = false;
          foreach ($this->headers[$key] as $hvalstr) {
            if (preg_match("!$cvalue!i", $hvalstr))
              $res = true;
          }
          if (!$res)
            return false;
        }
      }
    }
    return true;
  }
  
  // télécharge la pièce jointe définie par son chemin
  function dlAttached(array $path, bool $debug) { $this->body()->dlAttached($path, $debug); }
}


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


if (1) { // Test explodeListEmails()
  Message::testExplodeEmails();
  die("FIN testExplodeEmails");
}

