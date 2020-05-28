<?php
/*PhpDoc:
name: tree.inc.php
title: tree.inc.php - définition de la classe Tree implémentant un arbre de string restituable facilement
doc: |
  L'arbre doit être construit en boottom-up.
  Une restitution JSON peut être effectuée avec asArray().
  Show() effectue un affichage simple.

  class Tree {
    function __construct(string $label, array $children); // création d'un noeud, $children doit être un array de Tree
    function asArray(); // retourne un array pour un noeud, une chaine pour une feuille
    function show(int $level=0); // affiche l'arbre, une ligne par noeud ou feuille
  };

journal: |
  30/3/2020:
    - création
functions:
classes:
*/

class Tree { // classe basique implémentant un arbre de string restituable facilement soit sur plusieurs lignes soit comme JSON
  protected $label; // string
  protected $children; // [ Tree ]

  function __construct(string $label, array $children) { // création d'un noeud, $children doit être un array de Tree
    $this->label = $label;
    $this->children = $children;
  }

  function asArray() { // retourne un array pour un noeud, une chaine pour une feuille
    $children = [];
    foreach ($this->children as $child)
      $children[] = $child->asArray();
    return $children ? [ $this->label => $children ] : $this->label;
  }

  function show(int $level=0) { // affiche l'arbre, une ligne par noeud ou feuille
    echo str_repeat('----', $level),$this->label,"\n";
    foreach ($this->children as $child) {
      $child->show($level+1);
    }
  }
};
