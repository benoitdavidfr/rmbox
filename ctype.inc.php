<?php
/*PhpDoc:
name: ctype.inc.php
title: ctype.inc.php - analyse du Content-Type
doc: |
  L'objectif est de concentrer dans la classe CType et ses ous-classes le code d'analyse des Content-Type,
  aussi bien celui des messages que celui des parties de messages.
  Cela permet d'éviter de répartir cette analyse dans le code et de facilite sa fiabilisation.
  Ces lignes de texte sont assez variables.
  Un Content-Type est soit celui d'un Body multi-parties soit celui d'un Body-mono-partie.
  La distinction est faite en fonction du premier mot avant le caractère / qui est dans le premier cas 'multipart'.

  Le code est utilisé dans 2 cas différents:
    1) Pour une première analyse succincte qui va permettre de réduire le nombre de cas distincts en supprimant les parties
       variables, qui sont:
      a) pour les multi-parties la chaine utilisée comme frontière entre parties, et
      b) pour les mono-parties le nom du fichier indiqué dans un champ name ou name* ou des champs name*0* et name*1*
    2) Pour une analyse détaillée dont l'objectif est de décomposer le Content-Type en constituants.

  Dans un premier temps, ce code est mis au point au travers des commandes listContentType et parseContentTypes
  Dans un second temps il pourra être utilisé dans mbox.inc.php pour le fiabiliser !
journal: |
  25/3/2020:
    - création
functions:
classes:
*/

/*PhpDoc: classes
name: abstract class CType
title: abstract class CType - le type abstrait générique
doc: |
*/
abstract class CType {
  protected $srce;
  
  // indique si le Content-Type correspond ou non à un type multi-parties
  static function testIsMulti(string $cType): bool {
    return (strncmp($cType, 'multipart/', 10) == 0);
  }
  
  // supprime la boundary ou le name pour rendre le type plus simple et limiter le nombre de cas
  static function simplified(string $cType): string {
    if (self::testIsMulti($cType)) {
      if (preg_match('!^(.*)boundary=("[^"]*"|[^;]*)(;.*)?$!', $cType, $matches))
        return $matches[1].'boundary="---"'.($matches[3] ?? '');
      throw new Exception("No match on '$cType'");
    }
    else {
      if (preg_match('!^(.*)(name|name\*)="[^"]*"(.*)$!', $cType, $matches)) {
        return "$matches[1]$matches[2]=\"xxx\"$matches[3]";
      }
      elseif (preg_match('!^(.*)(name|name\*)=[^;]*(;.*)?$!', $cType, $matches)) {
        return "$matches[1]$matches[2]=xxx".($matches[3] ?? '');
      }
      elseif (preg_match('!^(.*)(name\*0\*)=[^;]*; *(name\*1\*)=[^;]*(;.*)?$!', $cType, $matches)) {
        return "$matches[1]$matches[2]=xxx; $matches[3]=xxx".($matches[4] ?? '');
      }
      else
        return $cType;
    }
  }
  
  static function create(string $cType): CType {
    if (self::testIsMulti($cType))
      return new CTypeMulti($cType);
    else
      return new CTypeMono($cType);
  }

  function __toString(): string { return $this->srce; }
};

/*PhpDoc: classes
name: class CTypeMono extends CType
title: class CTypeMono extends CType - Content-Type correspondant à un Body mono-partie
doc: |
*/
class CTypeMono extends CType {
  protected $type = '';
  protected $params = []; // [ key => value ]
  protected $name = '';
  
  function __construct(string $cType) {
    $this->srce = $cType;
    if ($cType == '')
      return;
    $pattern1 = '!^([^;]*)!'; // exemple 'text/calendar'
    if (!preg_match($pattern1, $cType, $matches))
      throw new Exception("no match for '$cType'");
    $this->type = strToLower($matches[1]);
    $cType = preg_replace($pattern1, '', $cType);
    $pattern2 = '!^; *([^=]+)=("([^"]*)"|[^;]*)!'; // exemples '; charset="utf-8"' ou '; method=REQUEST'
    while (preg_match($pattern2, $cType, $matches)) {
      $this->params[strToLower($matches[1])] = $matches[3] ?? $matches[2];
      $cType = preg_replace($pattern2, '', $cType);
    }
    if ($cType && ($cType <> ';'))
      throw new Exception("Reste '$cType' dans l'analyse de '".$this->srce."'");
  }
  
  function isMulti() { return false; }
  function type() { return $this->type; }
  function charset() { return isset($this->params['charset']) ? strToLower($this->params['charset']) : null; }
};

/*PhpDoc: classes
name: class CTypeMono extends CType
title: class CTypeMono extends CType - Content-Type correspondant à un Body multi-parties
doc: |
*/
class CTypeMulti extends CType {
  protected $subtype;
  protected $boundary;
  
  function __construct(string $cType) {
    $this->srce = $cType;
    $pattern = '!^m?ultipart/(alternative|mixed|related|report);'
        .'( +report-type=([^;]*);)?'
        .'( +differences=([^;]*);)?'
        .'( +type="([^"]*)";)?'
        .' +boundary=("([^"]*)"|[^";]*)(;.*)?$!';
    if (preg_match($pattern, $cType, $matches)) {
      $this->subtype= $matches[1];
      $this->boundary = $matches[9] ?? ($matches[8] ?? '');
    }
    else
      throw new Exception("no match for '$cType'");
  }
  
  function isMulti() { return true; }
  function subtype() { return $this->subtype; }
};
