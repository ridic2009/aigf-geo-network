---
title: Sécuriser son Compte de Compagnon IA
slug: securite-du-compte-compagnon-ia
status: published
type: article
category: privacy
translation_key: account-security
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  Un compte contenant des mois de conversations intimes vaut davantage, pour qui
  s'en empare, qu'un identifiant de boutique en ligne — et ces applications
  protègent les comptes nettement moins bien qu'une banque. Quatre gestes comblent
  l'essentiel de l'écart.
products:
  - nomi
  - replika
faq:
  - question: Ces applications proposent-elles la double authentification ?
    answer: >
      Certaines oui, beaucoup non. Cela vaut la peine de vérifier avant de vous
      abonner, car c'est un indicateur raisonnable du sérieux de l'éditeur sur le
      reste de sa sécurité.
  - question: Que se passe-t-il si mon compte est pris ?
    answer: >
      Celui qui l'a peut tout lire, générer en votre nom et souvent changer
      l'adresse e-mail. La récupération est lente sur ce marché : les équipes de
      support sont petites et les vérifications d'identité minces.
  - question: Dois-je utiliser une vraie adresse e-mail ?
    answer: >
      Utilisez une adresse dédiée que vous contrôlez. Cela sépare le compte du
      reste de votre identité sans dépendre de l'application.
related:
  - articles/what-the-app-knows
  - articles/shared-device
seo:
  title: Sécurité du Compte de Compagnon IA — Mots de Passe, 2FA et Sessions
  description: Pourquoi ces comptes valent la peine d'être pris, ce que ces applications ne protègent généralement pas, et les quatre changements qui comptent le plus.
  primary_keyword: sécurité compte petite amie ia
indexing:
  index: true
  follow: true
---
Pensez à ce que contient un de ces comptes après six mois : des conversations plus
franches que n'importe quel e-mail, des images générées, un moyen de paiement, et
une adresse e-mail qui relie le tout au reste de votre vie.

Considérez ensuite que l'éditeur est généralement une petite société avec une
petite équipe technique et aucun régulateur qui regarde par-dessus son épaule. Cet
écart — valeur élevée, protection ordinaire — est tout le problème.

## Ce que ces applications ne font généralement pas

Pas universellement, mais assez souvent pour le supposer tant que vous n'avez pas
vérifié :

- **Pas de double authentification.** Un mot de passe est la seule chose entre le
  compte et quiconque détient votre e-mail.
- **Pas de liste des sessions.** Vous ne voyez pas où vous êtes connecté et ne
  pouvez pas révoquer une session inconnue.
- **Pas d'alerte de connexion.** Une connexion depuis un nouvel appareil passe en
  silence.
- **Une récupération de compte mince**, ce qui coupe des deux côtés : difficile
  pour vous si vous êtes bloqué, facile pour qui se fait passer pour vous auprès
  du support.
- **Des sessions à longue durée de vie**, si bien qu'un appareil abandonné peut
  rester connecté des mois.

Quand une application propose 2FA et liste des sessions, cela mérite d'être
remarqué. Cela indique généralement une équipe qui a aussi réfléchi au reste.

## Les quatre choses qui comptent

### 1. Un mot de passe unique, issu d'un gestionnaire

Évident, et c'est pourtant toute la partie. La menace réaliste n'est pas quelqu'un
qui attaque cette application, mais le bourrage d'identifiants : un mot de passe
fuité d'un service sans rapport est essayé ici.

Un gestionnaire rend cela gratuit. Tout ce que vous réutilisez est à une fuite sans
rapport d'appartenir à quelqu'un d'autre.

### 2. Une adresse e-mail dédiée

Cela fait deux choses à la fois : le compte n'est plus trouvable depuis votre
identité principale, et la compromission de l'un n'entraîne pas celle de l'autre.

Utilisez une vraie boîte que vous contrôlez plutôt qu'une adresse jetable : vous
devez pouvoir recevoir une réinitialisation dans un an. La même adresse doit
recevoir les reçus, pour les raisons exposées dans
[la confidentialité des paiements](/articles/confidentialite-des-paiements-ia/).

### 3. Activez la 2FA là où elle existe

Si l'application la propose, utilisez-la, et préférez une application
d'authentification au SMS. Si elle n'existe pas, protégez plutôt le **compte
e-mail** par 2FA : c'est le chemin de récupération, et le sécuriser couvre toutes
les applications capables de vous envoyer un lien de réinitialisation.

C'est le geste le plus utile disponible quand l'application elle-même ne vous offre
rien.

### 4. Déconnectez les appareils dont vous avez fini

Surtout ceux qui sont partagés ou empruntés. Si l'application n'a pas de liste de
sessions, changer le mot de passe est l'instrument grossier qui, dans la plupart
des implémentations, invalide les autres sessions.

## La menace qui n'est pas technique

Il vaut la peine de la nommer clairement, car c'est elle qui fait de vrais dégâts
dans cette catégorie : quelqu'un ayant accès à votre appareil déverrouillé.

Aucune politique de mot de passe n'y répond. Le travail pertinent se situe du côté
de l'appareil, et il est décrit dans
[utiliser un compagnon sur un appareil partagé](/articles/compagnon-ia-appareil-partage/).

## Si une application est compromise

Cela arrive, et le protocole est toujours le même. Changez immédiatement le mot de
passe, et partout où vous l'aviez réutilisé. Surveillez l'adresse e-mail pour des
réinitialisations que vous n'avez pas demandées. Vérifiez le moyen de paiement et
envisagez de remplacer le numéro de carte. Puis décidez si vous restez : une fuite
traitée par un avis clair et un calendrier réel est un signal différent d'une fuite
révélée trois mois plus tard par des journalistes.

Lisez ce que l'éditeur dit réellement. Le flou sur le périmètre — c'est-à-dire sur
l'inclusion ou non des conversations — est en soi une réponse.

## Avant de vous abonner

Deux minutes dans les réglages avant de payer en disent plus sur le sérieux d'un
éditeur que n'importe quelle page marketing. Y a-t-il une 2FA ? Une liste des
sessions ? Une suppression en libre-service, ou seulement une adresse de support ?

Les applications conçues pour accumuler des années d'historique —
[Nomi](/avis/nomi-avis/), Replika — sont celles où cela pèse le plus, simplement
parce que ce sont elles qui finiront par détenir le plus de choses sur vous. Ce que
contient ce matériau est dans
[ce qu'elles savent de vous](/articles/ce-que-votre-application-ia-sait-de-vous/),
et comment s'en défaire dans
[supprimer son compte](/articles/supprimer-son-compte-compagnon-ia/).
