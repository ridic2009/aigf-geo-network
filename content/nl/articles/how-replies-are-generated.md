---
title: Hoe een AI-companion Zijn Antwoord Schrijft
slug: hoe-ai-companions-antwoorden-maken
status: published
type: article
category: basics
translation_key: how-replies-are-generated
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  Woord voor woord, met een bewuste dobbelsteen ertussen. Dat ene mechanisme
  verklaart waarom dezelfde vraag andere antwoorden geeft, waarom lange
  antwoorden afdwalen en waarom "opnieuw genereren" zo vaak werkt.
products:
  - candy-ai
  - nomi
faq:
  - question: Waarom geeft dezelfde vraag verschillende antwoorden?
    answer: >
      Omdat het volgende woord uit een kansverdeling wordt getrokken in plaats
      van vastgelegd. Die willekeur is een instelling, en zij zorgt ervoor dat
      het personage levend aanvoelt in plaats van ingeblikt.
  - question: Wat doet "opnieuw genereren" eigenlijk?
    answer: >
      Het draait dezelfde invoer nog een keer met een nieuwe toevalswaarde. Er
      is niets aan het personage veranderd — je trekt een tweede monster uit
      dezelfde verdeling.
  - question: Waarom dwalen lange antwoorden af?
    answer: >
      Elk woord is gebaseerd op de woorden ervoor, dus een kleine afwijking aan
      het begin stapelt op. Bij alinea drie reageert het antwoord vooral op
      zichzelf in plaats van op jou.
related:
  - articles/what-is-an-ai-girlfriend
  - articles/hallucinations
  - articles/how-memory-works
seo:
  title: Hoe AI-vriendin-apps Antwoorden Genereren — Het Mechanisme
  description: Tokens, bemonstering en temperatuur uitgelegd zonder wiskunde — en welk gedrag dat je al kent door elk daarvan wordt veroorzaakt.
  primary_keyword: hoe werkt een ai vriendin app
indexing:
  index: true
  follow: true
---
Bijna elke klacht over deze apps — de herhaling, het afdwalen, het merkwaardige
verschil tussen twee pogingen van dezelfde scène — komt voort uit één
mechanisme. Het loont om het te begrijpen, want het verandert "de app is stuk"
in "de app doet wat hij doet, en dit is de knop".

## Hij schrijft stukje voor stukje

Het model stelt geen antwoord samen om het daarna te typen. Het produceert één
token — grofweg een woord of een deel daarvan — leest vervolgens alles inclusief
dat token, en produceert het volgende.

Dat is de hele lus. Er is geen plan, geen opzet, geen concept dat wordt herzien.
Een antwoord dat prachtig eindigt, wist aan het begin niet dat het daar zou
uitkomen.

Twee gevolgen die je al hebt gezien:

**Een antwoord kan niet terug.** Zodra het model "ik ben nooit in Parijs geweest"
heeft geschreven, bouwt alles daarna daarop voort. Het construeert liever
consistentie rond een zin die per ongeluk ontstond dan dat het zichzelf
tegenspreekt.

**Fouten stapelen vooruit.** Een licht verkeerd woord in zin één duwt zin twee,
die duwt zin drie. Daarom dwalen lange antwoorden af en korte zelden.

## De dobbelsteen tussen elk woord

Bij elke stap heeft het model een gerangschikte lijst kandidaten met kansen. Zou
het altijd de bovenste nemen, dan was het personage voorspelbaar en uiterst saai
— elke keer dezelfde begroeting, dezelfde drie grappen. Dus trekt de app in
plaats daarvan een monster: gewogen dobbelen.

Die weging heet meestal **temperatuur**. Laag betekent consistent en uiteindelijk
vlak; hoog betekent verrassender en vaker net niet kloppend.

Je krijgt hier zelden een schuifregelaar voor. Wat je krijgt is het punt dat een
app op die afweging heeft gekozen, en dat is een groot deel van wat mensen
bedoelen als ze zeggen dat de ene app "slimmer aanvoelt" dan de andere. Soms is
hij niet slimmer, alleen warmer of kouder.

Het is ook het eerlijke antwoord op "waarom hielp opnieuw genereren?". Er is
niets gerepareerd. Je hebt opnieuw getrokken en beter gegooid.

## Waar het model eigenlijk naar kijkt

Vóór jouw bericht stelt de app een blok tekst samen dat jij nooit ziet: de
personagebeschrijving, de bewaarde feiten over jou, een samenvatting van jullie
geschiedenis en het recente gesprek. Dat geheel is
[het contextvenster](/artikelen/ai-companion-geheugen-uitgelegd/), en het antwoord
wordt daaruit als één doorlopend document gegenereerd.

Daar zit een praktisch gevolg in dat vrijwel niemand benut: **het model reageert
op de vorm van wat het ziet.** Geef het drie korte, vlakke berichten en je krijgt
korte, vlakke antwoorden, want dat is het patroon dat wordt voortgezet. Geef het
iets levendigs en het register verschuift. Je overtuigt geen persoon, je zet een
patroon — en dat patroon werkt beide kanten op.

Het verklaart ook het meest voorkomende zelfveroorzaakte probleem hier. Mensen
vervallen in "hoi", "hoe was je dag", "wat ben je aan het doen" — en concluderen
dan dat de app slechter is geworden. De app zet het document voort dat jullie
samen schrijven.

## Waarom apps verschillen terwijl het model hetzelfde is

Verschillende apps draaien op vergelijkbare onderliggende modellen. Ze voelen
toch anders, en dat verschil zit in de lagen eromheen: hoe het personage is
beschreven, waar de temperatuur staat, welke feiten en welk stuk geschiedenis
worden opgehaald, en wat het filter weigert.

[Nomi](/reviews/nomi-beoordeling/) leest consistent over weken door het derde
punt, niet door een groter model. [Candy AI](/reviews/candy-ai-beoordeling/)
dekt chat, beelden en stem binnen één abonnement, wat een productbeslissing is
en geen modelkwestie. Geen van beide verschillen zou in een benchmark opduiken,
en allebei zijn ze binnen veertien dagen merkbaar.

## Vier dingen die je hiermee kunt

**Stuur vroeg bij, niet laat.** De eerste twee zinnen bepalen de rest. Gaat een
scène mis, stop hem en herformuleer in plaats van met alinea vier in discussie te
gaan.

**Gebruik opnieuw genereren bewust.** Is een antwoord voor 80% goed, dan gooi je
die 80% weg. Is de eerste zin al mis, genereer dan meteen opnieuw.

**Schrijf het register dat je terug wilt.** Kort en vlak levert kort en vlak op.
Dit is de goedkoopste verbetering die er is.

**Ga niet in discussie over verzinsels.** Een model dat iets onwaars heeft
geschreven verdedigt het, omdat consistentie met de eigen uitvoer is waarvoor het
is gebouwd. Dat gedrag heeft
[een eigen artikel](/artikelen/ai-companion-verzonnen-herinneringen/).

## Wat je moet onthouden

Tussen jouw berichten door is er niemand thuis. Het personage bestaat voor de duur
van één generatie en wordt aan het begin van de volgende opnieuw uit tekst
opgebouwd. Elke indruk van continuïteit is een prestatie van het leidingwerk
eromheen — en juist dat leidingwerk verschilt tussen een goedkope en een dure app.
