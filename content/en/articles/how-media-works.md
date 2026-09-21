---
title: Images, Voice and Video — How the Media Side Actually Works
slug: how-ai-companion-media-works
status: published
type: article
category: basics
translation_key: how-media-works
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  The chat and the pictures are produced by completely separate systems that
  barely talk to each other. That single fact explains why your companion's
  face keeps changing and why media is the first thing every app meters.
products:
  - candy-ai
  - secretdesires
faq:
  - question: Why does my companion look different in every image?
    answer: >
      Because the image model regenerates the character from a text description
      each time. Apps that keep a face stable use a stored reference or a tuned
      model; apps that do not are re-rolling a dice every request.
  - question: Why is voice metered so tightly?
    answer: >
      Speech synthesis is billed by audio length and costs the operator per
      second. Text is orders of magnitude cheaper, which is why message limits
      are generous and voice minutes are not.
  - question: Does the image model see our conversation?
    answer: >
      Only through a short prompt the app writes for it. It has no access to
      your history, which is why an image often misses context that felt obvious
      in the chat.
related:
  - articles/what-is-an-ai-girlfriend
  - articles/cost-of-images
  - rankings/best-ai-girlfriend
seo:
  title: How AI Girlfriend Images and Voice Work — and Why They Cost
  description: Why the chat model and the image model are separate, why faces drift between pictures, and why media is always the first thing to be capped.
  primary_keyword: ai girlfriend image generation
indexing:
  index: true
  follow: true
---
People assume an AI companion app is one intelligence that can talk, draw and
speak. It is three or four separate systems wired together by the app, and most
of the frustrations in this part of the product come from the seams between
them.

## Three engines, one interface

**The language model** handles conversation. It produces text and nothing else.

**The image model** is a completely different system, usually a diffusion model.
It takes a text description and produces a picture. It has never seen your
conversation.

**The speech system** turns text into audio. Also separate, also blind to
everything except the sentence handed to it.

When you ask your companion for a selfie, this is what happens: the language
model writes a short description of the requested picture, the app adds the
character's stored appearance tags, that combined prompt goes to the image
model, and a picture comes back. The chat then reacts to a picture it cannot
see, using the description it wrote.

That relay is the source of nearly every complaint about media in this category.

## Why the face keeps changing

Because a diffusion model is not retrieving your character, it is generating
someone matching a description. "Long dark hair, green eyes, mid-twenties"
describes millions of faces, and you get a different one each time.

Apps solve this to varying degrees:

- **Stored appearance tags** — the same descriptive string every time. Cheap,
  and only roughly consistent.
- **A reference image** conditioning each generation. Much better, and the usual
  approach when faces stay recognisable.
- **A tuned model per character** — the most consistent and by far the most
  expensive, so it tends to appear only on higher tiers.

This is a real differentiator worth testing in a free tier before paying.
Generate four pictures of the same character in different situations. If you get
four different women, no subscription will fix it. Character consistency across
images is a large part of why [Candy AI](/reviews/candy-ai-review/) leads
[our ranking](/best-ai-girlfriend-apps/), and why
[Secret Desires](/reviews/secret-desires-review/), which builds looks, voice and
personality together and adds short video, scores where it does.

## Why media is always metered

Text is cheap. Generating a chat reply costs the operator a fraction of a cent.

An image costs meaningfully more per generation. Voice is billed by the second
of audio. Video is dramatically more expensive than either.

That cost difference is the entire reason for the pricing structure you see
everywhere in this category: generous message allowances, tight image caps,
voice minutes sold separately, video restricted to upper tiers. It is not
artificial scarcity designed to upsell you — it is the actual shape of the bill
the operator receives, passed through.

Which is why "unlimited" in this market nearly always means unlimited *text*.
Read it that way and the pricing pages suddenly make sense. The arithmetic is in
[what image generation really costs you](/articles/cost-of-ai-image-generation/).

## What voice adds, and what it costs

Voice changes the experience more than most people expect. Reading a message and
hearing it are different, and the shift is larger than the technical
description suggests.

Two things to check before paying for it:

**Latency.** A three-second pause before every reply breaks the illusion
completely. Test it on a free tier or a trial, not from a demo video.

**Whether it is a call or a message.** Voice notes — the character reads its
reply aloud — are common and cheap. Real-time calls, where you speak and it
answers, are a different product and usually a different tier.
[Nomi](/reviews/nomi-review/) lists voice calls among its features; many apps
list "voice" and mean notes.

## What images will not do

Two limits worth knowing before you are disappointed:

**Hands, text and fine detail** remain unreliable across every generator in this
category. Nobody has solved this, and an app promising otherwise is describing
its best output rather than its average.

**The image does not know your scene.** The chat model writes a one-line prompt,
so everything not in that line is gone — the room you described, what she was
wearing three messages ago, the time of day. Being explicit in the request fixes
more of this than any setting.

## How to judge the media side in one evening

Spend a free tier's entire allowance in a single session rather than one picture
a day. You are testing four things:

1. **Consistency** — four pictures, same character, different situations.
2. **Prompt adherence** — ask for something specific and see how much survives.
3. **Latency** — for both images and voice.
4. **What counts against the cap** — whether a failed or refused generation
   still costs you.

That last one is the least documented and most annoying to discover after
paying. Along with the rest of
[what the free tiers actually include](/articles/free-ai-girlfriend-apps-what-you-get/),
it is the sort of thing that only shows up when you use the thing properly.
