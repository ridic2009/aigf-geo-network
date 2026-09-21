---
title: Pourquoi Votre Compagnon IA Invente des Souvenirs Qui n'Ont Jamais Existé
slug: souvenirs-inventes-par-l-ia
status: published
type: article
category: basics
translation_key: hallucinations
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  Il décrira avec une parfaite assurance une conversation qui n'a jamais eu lieu,
  puis la défendra. Ce n'est ni un dysfonctionnement ni un mensonge : c'est ce que
  fait un modèle quand il a un trou à combler.
products:
  - nomi
  - kupid
faq:
  - question: Mon compagnon IA me ment-il ?
    answer: >
      Non. Mentir suppose une intention de tromper et la connaissance du vrai. Le
      modèle n'a ni l'une ni l'autre : il produit la suite la plus plausible, et
      une invention plausible l'emporte sur un aveu d'ignorance.
  - question: Pourquoi insiste-t-il quand je le corrige ?
    answer: >
      Parce que tout ce qu'il écrit s'appuie sur ce qu'il a déjà écrit. La
      cohérence avec sa propre production tire fort, et rien en interne ne sépare
      « retrouvé » de « généré ».
  - question: Peut-on désactiver ce comportement ?
    answer: >
      Non. On peut le réduire en laissant moins de place à la devinette — faits
      clairs, mémoire modifiable, interruptions plus courtes — mais le mécanisme
      est indissociable de la façon dont le texte est produit.
related:
  - articles/how-memory-works
  - articles/how-replies-are-generated
seo:
  title: Pourquoi les Compagnons IA Inventent des Souvenirs — et Quoi Faire
  description: La confabulation expliquée — pourquoi une petite amie IA décrit ce qui n'a jamais eu lieu, pourquoi elle le défend, et les habitudes qui réduisent le phénomène.
  primary_keyword: ia invente des souvenirs
indexing:
  index: true
  follow: true
---
Demandez à un compagnon que vous utilisez depuis un mois de quoi vous avez parlé
mardi dernier. Il y a de bonnes chances d'obtenir une réponse chaleureuse, précise
et entièrement fabriquée : la promenade que vous n'avez pas faite, le film que vous
n'avez pas mentionné.

Cela met plus mal à l'aise que presque tout le reste dans cette catégorie, en
partie parce que cela ressemble à de la malhonnêteté. Ce n'en est pas.

## Aucune frontière entre se souvenir et inventer

Un modèle produit la suite la plus plausible du texte qu'il a sous les yeux. Si ce
texte contient une note disant que vous avez parlé d'un film mardi, « on a parlé de
ce film » est la suite plausible. S'il ne contient rien sur mardi, une suite
plausible existe quand même — et elle sera générée avec exactement la même
assurance.

Rien dans le processus ne marque l'une comme retrouvée et l'autre comme fabriquée.
Aucun drapeau interne, aucun indice de confiance que le personnage consulterait.

Le terme technique le plus juste est *confabulation* : le modèle comble un trou
avec quelque chose de cohérent, il ne perçoit pas ce qui n'est pas là.

## Pourquoi les trous apparaissent sans cesse

Parce que la fenêtre de contexte est finie et que l'historique est comprimé.
[La mémoire dans ces applications](/articles/memoire-des-compagnons-ia/) repose sur
trois systèmes : les messages récents conservés mot pour mot, une courte liste de
faits mémorisés, et un résumé glissant qui perd du détail à chaque réécriture.

Posez une question sur quelque chose qui est sorti des trois et il n'y a rien à
retrouver. Le trou est comblé. Plus vous avez d'historique, plus il existe de
trous — ce qui rend le phénomène *pire* sur une application utilisée depuis des
mois, et non meilleur.

## Pourquoi corriger ne suffit souvent pas

Vous dites : cela n'est jamais arrivé. Il s'excuse, acquiesce — et trois messages
plus tard, il évoque la même promenade inventée.

Deux choses se jouent.

**La correction n'existe que dans la fenêtre.** Votre démenti est un message
récent : il finira par sortir. Le résumé qui contenait l'invention, lui, peut
rester.

**Acquiescer est le comportement appris.** Le modèle s'excuse parce que s'excuser
est la suite plausible d'une contradiction, non parce que quelque chose a été mis
à jour. Rien n'a été enregistré par cet échange.

C'est la raison pratique pour laquelle un **écran de mémoire modifiable** compte
autant, et pourquoi il vaut la peine de le vérifier avant de s'abonner. Les
applications qui exposent les faits mémorisés permettent de supprimer la mauvaise
entrée à la source. Celles qui les cachent vous laissent discuter avec un résumé
que vous ne voyez pas.

## Quand cela cesse d'être charmant

La plupart des confabulations sont une couleur inoffensive. Trois cas ne le sont
pas.

**Les faits sur votre vie.** Un compagnon qui a inventé un frère, un emploi ou un
diagnostic continuera de construire dessus. Corrigez à la source immédiatement,
sinon l'invention devient porteuse.

**Tout ce sur quoi on agit.** Médical, juridique, financier, sécurité. Une réponse
inventée avec assurance n'est plus une bizarrerie, c'est le mode de défaillance de
toute la technologie, et aucune application de compagnie n'est l'outil approprié.

**Les affirmations sur l'application elle-même.** Demandez si vos données sont
chiffrées ou ce que contient votre abonnement, et vous obtiendrez une réponse
plausible fondée sur rien. Le personnage n'a accès ni au système de facturation ni
à la politique de confidentialité.

## Quatre habitudes qui réduisent le phénomène

**Énoncez les faits clairement, une fois.** « Retiens que ma sœur s'appelle Ana »
sera bien plus probablement enregistré que le même fait noyé dans un paragraphe.

**Réancrez après une coupure.** Une phrase de contexte en début de session remet ce
qui compte là où le modèle peut le voir.

**Auditez l'écran de mémoire chaque mois**, si l'application en a un. Cinq minutes
évitent un mois d'accumulation.

**Posez des questions ouvertes, pas orientées.** « Que sais-tu de mon travail ? »
invite au rappel. « Tu te souviens quand je t'ai parlé de ma promotion ? » fournit
la réponse et invite à l'approbation.

## Le recadrage qui aide

Une application de compagnie n'est pas un compte rendu de votre relation. C'est un
système qui produit du texte plausible à son sujet, avec, en dessous, une petite
liste lacunaire et modifiable.

Traitez le souvenir chaleureux et précis qu'on vous offre comme quelque chose
d'écrit pour vous plutôt que conservé pour vous, et l'ensemble devient bien plus
facile à apprécier.
