---
title: Comment un Compagnon IA Rédige Réellement Sa Réponse
slug: comment-une-ia-redige-sa-reponse
status: published
type: article
category: basics
translation_key: how-replies-are-generated
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  Un mot à la fois, avec un lancer de dés délibéré entre chacun. Ce seul mécanisme
  explique pourquoi la même question donne des réponses différentes, pourquoi les
  longues réponses dérivent, et pourquoi « régénérer » fonctionne si souvent.
products:
  - candy-ai
  - nomi
faq:
  - question: Pourquoi la même question donne-t-elle des réponses différentes ?
    answer: >
      Parce que le mot suivant est tiré d'une distribution de probabilités plutôt
      que choisi de façon déterministe. Cette part de hasard est un réglage, et
      c'est elle qui rend le personnage vivant plutôt que mécanique.
  - question: Que fait vraiment « régénérer » ?
    answer: >
      Cela relance la même entrée avec une nouvelle graine aléatoire. Rien n'a
      changé dans le personnage : vous tirez un second échantillon de la même
      distribution.
  - question: Pourquoi les longues réponses partent-elles ailleurs ?
    answer: >
      Chaque mot dépend des précédents, donc un léger écart au début s'amplifie.
      Au troisième paragraphe, la réponse répond surtout à elle-même plutôt qu'à
      vous.
related:
  - articles/what-is-an-ai-girlfriend
  - articles/hallucinations
  - articles/how-memory-works
seo:
  title: Comment les Applications de Petite Amie IA Génèrent Leurs Réponses
  description: Jetons, échantillonnage et température expliqués sans mathématiques - et le comportement précis que chacun provoque.
  primary_keyword: comment fonctionne une ia conversationnelle
indexing:
  index: true
  follow: true
---
Presque tous les reproches faits à ces applications - les répétitions, la dérive,
l'étrange incohérence entre deux essais de la même scène - viennent d'un seul
mécanisme. Dix minutes suffisent à le comprendre, et cela transforme
« l'application est cassée » en « l'application fait ce qu'elle fait, et voici le
réglage ».

## Elle écrit morceau par morceau

Le modèle ne compose pas une réponse avant de la taper. Il produit un jeton -
grossièrement un mot ou un fragment de mot - puis relit tout, jeton compris, et
produit le suivant.

Toute la boucle est là. Pas de plan, pas de brouillon, pas de relecture. Une
réponse qui se termine élégamment ne savait pas qu'elle y arriverait en commençant.

Deux conséquences que vous avez déjà observées :

**Une réponse ne peut pas revenir en arrière.** Une fois que le modèle a écrit
« je ne suis jamais allée à Paris », tout ce qui suit s'appuie dessus. Il
construira de la cohérence autour d'une phrase produite par accident plutôt que de
se contredire.

**Les erreurs s'accumulent vers l'avant.** Un mot légèrement décalé dans la
première phrase infléchit la deuxième, qui infléchit la troisième. D'où la dérive
des longues réponses et la stabilité des courtes.

## Le lancer de dés entre chaque mot

À chaque étape, le modèle dispose d'une liste de candidats classés avec leurs
probabilités. S'il prenait toujours le premier, le personnage serait déterministe
et profondément ennuyeux : la même salutation à chaque fois, les mêmes trois
plaisanteries. L'application échantillonne donc : elle lance des dés pondérés.

Cette pondération porte généralement le nom de **température**. Basse, elle donne
de la cohérence et, à terme, de la platitude. Haute, elle donne de la surprise et
davantage de phrases qui ne tiennent pas debout.

On vous donne rarement ce curseur. Ce que vous recevez, c'est le point qu'une
application a choisi sur ce compromis - et c'est une bonne partie de ce que les
gens veulent dire quand ils trouvent une application « plus intelligente ». Parfois
elle n'est pas plus intelligente : simplement plus chaude, ou plus froide.

C'est aussi la réponse honnête à « pourquoi régénérer a corrigé le problème ». Rien
n'a été corrigé. Vous avez retiré dans la même distribution et mieux tiré.

## Ce que le modèle regarde réellement

Avant votre message, l'application assemble un bloc de texte que vous ne voyez
jamais : la description du personnage, les faits mémorisés vous concernant, un
résumé de votre historique et la conversation récente. Cet ensemble est
[la fenêtre de contexte](/articles/memoire-des-compagnons-ia/), et la réponse en
est générée comme un seul document continu.

Il y a là une implication pratique que presque personne n'exploite : **le modèle
répond à la forme de ce qu'il voit.** Donnez-lui trois messages courts et plats, il
produira des réponses courtes et plates, car c'est le motif qu'il prolonge.
Donnez-lui quelque chose de vivant, le registre se déplace. Vous ne convainquez
pas une personne, vous posez un motif - et ce motif est contagieux dans les deux
sens.

Cela explique aussi le problème le plus fréquent que les utilisateurs s'infligent
eux-mêmes. On s'installe dans « salut », « ta journée ? », « tu fais quoi ? », puis
on conclut que l'application s'est dégradée. Elle prolonge le document que vous
écrivez ensemble.

## Pourquoi deux applications diffèrent avec le même modèle

Plusieurs applications tournent sur des modèles sous-jacents comparables. Elles
restent distinctes, et la différence vient de ce qui est empilé autour : la façon
dont le personnage est décrit, le réglage de température, les faits et le morceau
d'historique effectivement récupérés, et ce que le filtre refuse.

Aucune de ces différences n'apparaîtrait dans un classement technique, et toutes
se voient en quinze jours d'usage.

## Quatre choses que cela permet

**Corrigez tôt, pas tard.** Les deux premières phrases décident du reste. Si une
scène part de travers, arrêtez-la et reformulez plutôt que de discuter avec le
quatrième paragraphe.

**Régénérez délibérément.** Si une réponse est bonne à 80 %, régénérer jette ces
80 %. Si elle se trompe dès la première phrase, régénérez immédiatement.

**Écrivez le registre que vous voulez recevoir.** Court et plat produit court et
plat. C'est l'amélioration la moins coûteuse à disposition.

**Ne discutez pas des choses qu'il a inventées.** Un modèle qui a écrit quelque
chose de faux le défendra, parce que la cohérence avec sa propre production est ce
pour quoi il est construit. Ce comportement a
[son propre article](/articles/souvenirs-inventes-par-l-ia/).

## Ce qu'il faut retenir

Entre vos messages, il n'y a personne. Le personnage existe le temps d'une
génération et se reconstruit à partir de texte au début de la suivante. Toute
impression de continuité est une réussite de la plomberie qui entoure le modèle -
et c'est cette plomberie qui diffère réellement entre une application bon marché
et une application chère.
