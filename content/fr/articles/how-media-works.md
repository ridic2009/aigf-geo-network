---
title: Images, Voix et Vidéo — Comment Fonctionne Vraiment le Côté Média
slug: images-et-voix-des-compagnons-ia
status: published
type: article
category: basics
translation_key: how-media-works
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  La discussion et les images sont produites par des systèmes entièrement séparés
  qui se parlent à peine. Ce seul fait explique pourquoi le visage de votre
  compagne change sans arrêt et pourquoi le média est toujours limité en premier.
products:
  - candy-ai
  - secretdesires
faq:
  - question: Pourquoi ma compagne a-t-elle un visage différent à chaque image ?
    answer: >
      Parce que le modèle d'image reconstruit le personnage à partir d'une
      description textuelle à chaque fois. Les applications qui stabilisent un
      visage utilisent une image de référence ou un modèle ajusté ; les autres
      relancent les dés à chaque demande.
  - question: Pourquoi la voix est-elle si strictement limitée ?
    answer: >
      La synthèse vocale se facture à la durée d'audio et coûte à l'éditeur des
      secondes de calcul. Le texte est bien moins cher, d'où des quotas de
      messages généreux et des minutes de voix qui ne le sont pas.
  - question: Le modèle d'image voit-il notre conversation ?
    answer: >
      Seulement à travers une courte consigne que l'application rédige pour lui.
      Il n'a aucun accès à votre historique, et c'est pourquoi une image manque
      souvent un contexte qui semblait évident dans la discussion.
related:
  - articles/what-is-an-ai-girlfriend
  - articles/how-replies-are-generated
seo:
  title: Images et Voix des Petites Amies IA — Fonctionnement et Coût
  description: Pourquoi le modèle de discussion et le modèle d'image sont séparés, pourquoi les visages dérivent d'une image à l'autre, et pourquoi le média est toujours plafonné.
  primary_keyword: génération d'images petite amie ia
indexing:
  index: true
  follow: true
---
On imagine volontiers qu'une application de compagnie est une intelligence unique
capable de parler, de dessiner et de s'exprimer. Ce sont trois ou quatre systèmes
distincts reliés entre eux par l'application, et la plupart des frustrations de
cette partie du produit naissent dans les jointures.

## Trois moteurs, une seule interface

**Le modèle de langage** gère la conversation. Il produit du texte et rien d'autre.

**Le modèle d'image** est un système complètement différent. Il reçoit une
description textuelle et produit une image. Il n'a jamais vu votre conversation.

**La synthèse vocale** transforme du texte en audio. Séparée elle aussi, aveugle à
tout sauf à la phrase qu'on lui tend.

Quand vous demandez une photo à votre compagne, voici ce qui se passe : le modèle
de langage rédige une courte description de l'image demandée, l'application y
ajoute les attributs d'apparence enregistrés, cette consigne combinée part vers le
modèle d'image, et une image revient. La discussion réagit ensuite à une image
qu'elle ne peut pas voir.

Ce relais est à l'origine de presque tous les reproches faits au média ici.

## Pourquoi le visage change sans cesse

Parce qu'un modèle de diffusion ne récupère pas votre personnage : il génère
quelqu'un qui correspond à une description. « Longs cheveux bruns, yeux verts, la
petite trentaine » décrit des millions de visages, et vous en obtenez un différent
à chaque fois.

Les applications y répondent à des degrés divers : attributs d'apparence
enregistrés (bon marché, à peu près cohérent), image de référence conditionnant
chaque génération (bien meilleur), ou modèle ajusté par personnage (le plus
cohérent et de loin le plus coûteux, donc réservé aux offres supérieures).

C'est une différence réelle, testable dans une offre gratuite avant de payer.
Générez quatre images du même personnage dans quatre situations. Si vous obtenez
quatre femmes différentes, aucun abonnement ne corrigera cela.

## Pourquoi le média est toujours compté

Le texte coûte peu. Une réponse de discussion revient à une fraction de centime.

Une image coûte nettement plus par génération. La voix se facture à la seconde
d'audio. La vidéo est bien plus chère que les deux.

Cet écart de coût explique à lui seul la structure tarifaire que l'on retrouve
partout : quotas de messages généreux, plafonds d'images stricts, minutes de voix
vendues à part, vidéo réservée aux offres hautes. Ce n'est pas une rareté
artificielle destinée à vous faire monter en gamme, c'est la forme réelle de la
facture que reçoit l'éditeur.

D'où le fait qu'« illimité », sur ce marché, signifie presque toujours texte
illimité. Lisez-le ainsi et les pages tarifaires deviennent soudain lisibles.

## Ce que la voix apporte, et ce qu'elle coûte

La voix change l'expérience plus qu'on ne l'imagine. Lire un message et l'entendre
sont deux choses différentes.

Deux points à vérifier avant de payer. **La latence** : trois secondes d'attente
avant chaque réponse détruisent complètement l'illusion — testez vous-même, pas sur
une vidéo de démonstration. Et **s'il s'agit d'un appel ou d'un message vocal** :
la lecture à voix haute est courante et peu coûteuse, l'appel en temps réel est un
autre produit et généralement une autre offre.

## Ce que les images ne feront pas

Deux limites à connaître avant d'être déçu. **Les mains, le texte et les détails
fins** restent peu fiables chez tous les générateurs de la catégorie ; personne n'a
résolu cela. Et **l'image ne connaît pas votre scène** : le modèle de discussion
rédige une seule ligne, donc tout ce qui n'y figure pas disparaît — la pièce que
vous aviez décrite, ce qu'elle portait trois messages plus tôt, l'heure. Être
explicite dans la demande corrige plus que n'importe quel réglage.

## Juger le côté média en une soirée

Dépensez tout le quota gratuit en une seule session plutôt qu'une image par jour.
Vous testez quatre choses : la cohérence (quatre images, un personnage), le respect
de la consigne, la latence pour l'image et la voix, et — le point le moins
documenté et le plus agaçant à découvrir après avoir payé — si une génération
échouée ou refusée décompte quand même du quota.
