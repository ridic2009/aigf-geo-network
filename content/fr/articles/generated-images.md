---
title: Que Deviennent les Images Générées par un Compagnon IA
slug: confidentialite-des-images-generees
status: published
type: article
category: privacy
translation_key: generated-images
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  Chaque image que vous générez existe sur le stockage de quelqu'un d'autre,
  généralement derrière une adresse qui ne vérifie pas qui la demande, et souvent
  hors du périmètre de votre demande d'effacement.
products:
  - candy-ai
  - secretdesires
faq:
  - question: Les images générées sont-elles privées à mon compte ?
    answer: >
      La galerie l'est. Le fichier souvent non — beaucoup d'applications servent
      les images depuis un réseau de diffusion à une adresse longue et
      indevinable, que toute personne détenant le lien peut ouvrir.
  - question: Supprimer mon compte supprime-t-il les images ?
    answer: >
      Pas toujours. Les images vivent fréquemment dans un stockage distinct des
      conversations et ne sont pas automatiquement couvertes par la même demande.
      Posez la question explicitement.
  - question: Puis-je téléverser la photo d'une personne réelle ?
    answer: >
      Ne le faites pas. Tout éditeur sérieux l'interdit, c'est illégal dans un
      nombre croissant de pays, et c'est le moyen le plus rapide de perdre
      définitivement un compte.
related:
  - articles/how-media-works
  - articles/what-the-app-knows
seo:
  title: Confidentialité des Images Générées par une IA — Où Vivent-Elles
  description: Comment les images générées sont stockées et servies, pourquoi leurs adresses sont souvent publiques de fait, et ce qu'une demande d'effacement retire.
  primary_keyword: confidentialité images générées ia
indexing:
  index: true
  follow: true
---
La discussion est ce dont les gens s'inquiètent. Les images sont ce qui est le
plus exposé, ce qui persiste le plus longtemps, et ce que les politiques
réellement lues couvrent le moins.

## Où va le fichier

Quand une application génère une image, celle-ci est écrite dans un stockage objet
et servie par un réseau de diffusion de contenu — la même infrastructure que
n'importe quel site utilise pour ses images. Votre galerie est une liste de ces
fichiers.

Ce qui compte, c'est **comment le fichier est protégé**. Deux approches existent,
et la différence est importante.

**Adresses signées ou authentifiées.** Le lien expire, ou le serveur vérifie votre
session avant de servir le fichier. Copiez l'adresse, ouvrez-la ailleurs, vous
n'obtenez rien.

**Adresses longues et indevinables.** Le fichier se trouve à une adresse que
personne ne pourrait raisonnablement deviner, et quiconque la détient peut
l'ouvrir, connecté ou non, indéfiniment.

La seconde est extrêmement courante car elle est peu coûteuse et rapide. Elle
n'est pas déraisonnable — l'adresse est réellement indevinable — mais l'image est
protégée par le secret du lien plutôt que par un contrôle d'accès. Un lien partagé,
collé dans une messagerie ou capté par une extension de navigateur est un lien
public.

**Comment vérifier en dix secondes :** ouvrez une image générée dans un nouvel
onglet, copiez l'adresse, collez-la dans une fenêtre privée. Si elle se charge, le
fichier n'est protégé que par l'obscurité.

## Pourquoi l'effacement les manque souvent

Conversations et images sont généralement stockées dans des systèmes différents.
Une opération d'effacement construite contre la base des conversations n'atteint
pas nécessairement le stockage objet, et le réseau de diffusion peut conserver des
copies en cache un certain temps.

Ce n'est pas nécessairement de la mauvaise foi. C'est ce qui arrive quand
l'effacement est bâti sur la base principale et que les images vivent ailleurs.

Le bon réflexe, en fermant un compte, est de demander explicitement : *cela
inclut-il les images générées, et sous quel délai disparaissent-elles du stockage
et du cache ?* La séquence complète est dans
[supprimer son compte](/articles/supprimer-son-compte-compagnon-ia/).

## La modération conserve des copies

Les images sont analysées, automatiquement et parfois par des humains. Ce qui est
signalé est généralement conservé plus longtemps — souvent explicitement exclu de
l'effacement, car le conserver est la façon dont l'éditeur démontre sa conformité.

C'est normal, c'est écrit dans la plupart des politiques, et cela signifie que
l'image ayant déclenché un filtre est précisément celle qui survivra le plus
longtemps à votre compte.

## Les deux règles absolues

**Ne téléversez jamais la photo d'une personne réelle.** Ni une partenaire, ni une
célébrité, ni quelqu'un croisé sur une application de rencontre. Tout éditeur
crédible l'interdit ; un nombre croissant de juridictions sanctionne pénalement la
production d'images intimes d'une personne identifiable sans son consentement ; et
c'est la voie la plus rapide vers un bannissement définitif sans recours.

Ici, la technique et l'éthique pointent dans la même direction : la personne sur
la photo n'a pas consenti à figurer dans votre historique, et
[cet historique est conservé](/articles/ce-que-votre-application-ia-sait-de-vous/).

**Supposez que l'invite est stockée avec l'image.** Le texte que vous avez écrit
pour produire une image est généralement conservé à côté, et il est bien plus
révélateur que l'image. Les gens sont prudents avec les images et négligents avec
les invites.

## Si vous voulez les garder

Téléchargez ce que vous souhaitez conserver et traitez la galerie comme
temporaire. Les applications réorganisent leur stockage, changent d'offres, font
expirer le média des comptes inactifs et perdent parfois des choses.

Rangez-les ensuite quelque part que vous contrôlez — et réfléchissez à l'endroit.
Une photothèque synchronisée avec un compte familial a déplacé l'exposition, pas
supprimé.

## Ce que cela implique au moment de choisir

Deux choses à vérifier dans une offre gratuite, en plus des tests décrits dans
[le fonctionnement du média](/articles/images-et-voix-des-compagnons-ia/) : si les
adresses d'images sont authentifiées, avec le test de la fenêtre privée ci-dessus,
et si l'application propose un téléchargement groupé ou un export incluant le
média.

Les applications qui investissent vraiment dans le média —
[Candy AI](/avis/candy-ai-avis/) avec images et voix dans un seul abonnement,
Secret Desires avec génération d'images et de courtes vidéos — sont celles où vous
accumulerez le plus de fichiers. Ces dix secondes y valent donc davantage.
