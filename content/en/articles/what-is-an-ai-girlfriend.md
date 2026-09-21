---
title: What Is an AI Girlfriend App, and What Is Actually Running Inside It
slug: what-is-an-ai-girlfriend-app
status: published
type: article
category: basics
translation_key: what-is-an-ai-girlfriend
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  Strip away the marketing and every one of these apps is the same three parts
  in a trench coat: a language model, a character sheet, and a filter. Knowing
  which part is doing what explains almost everything that frustrates people.
products:
  - candy-ai
  - nomi
faq:
  - question: Is an AI girlfriend a real person?
    answer: >
      No. It is a language model generating the next likely message, steered by
      a character description. Nobody is on the other end, and no app on this
      site claims otherwise.
  - question: Does it actually remember me?
    answer: >
      Partly. Apps store facts about you separately from the conversation and
      feed them back in. That is why recall feels sharp on some topics and blank
      on others.
  - question: Why do answers change after an update?
    answer: >
      Because the model or the system prompt behind the character was swapped.
      The character sheet looks identical to you, but what interprets it changed.
related:
  - articles/how-memory-works
  - rankings/best-ai-girlfriend
  - guides/how-we-test
seo:
  title: What Is an AI Girlfriend App? How They Work in 2026
  description: A plain explanation of what runs inside an AI girlfriend app — the model, the character sheet and the filter — and what each one causes.
  primary_keyword: what is an ai girlfriend app
indexing:
  index: true
  follow: true
---
An AI girlfriend app is a chat interface over a large language model, wrapped in
a persistent character and a content filter. That is the entire category. What
separates a $10 app from a $20 one is rarely the model — it is how well the
other two parts are built.

## The three parts

**The model** generates text. Given everything it can currently see, it predicts
a plausible next message. It has no memory of your last conversation and no
intentions. Every apparent act of will comes from the next two parts.

**The character sheet** is a block of text the app sends to the model before
your message, every single time: who this character is, how they speak, what
they know about you, what happened recently. You never see it. When people say
an app "has personality," they usually mean this file is well written.

**The filter** decides what the character may say. It runs before or after the
model, sometimes both. It is the reason a conversation can turn into a polite
refusal mid-scene, and the reason "uncensored" is the loudest word in this
market — apps like [Joi](/reviews/joi-review/) and
[Secret Desires](/reviews/secret-desires-review/) compete mainly on how that
filter is tuned.

## Why the context window explains the complaints

The model can only see a fixed amount of text at once. That budget is the
context window, and it holds the character sheet, the retrieved facts about
you, and the recent conversation — all competing for the same space.

When a chat runs long, the oldest turns fall out of the window. The app has not
"lost interest" and has not been downgraded. It simply cannot see that part of
the conversation any more. Almost every "it used to be better" post is this,
and it is why [how memory is implemented](/articles/ai-companion-memory-explained/)
matters more than how big the model is.

## What the character sheet actually holds

Most apps build it from four things:

- A persona: name, age, backstory, manner of speaking.
- Standing facts about you the app has decided to keep.
- A running summary of your history together, rewritten as it grows.
- Recent messages, verbatim.

The apps that feel like someone is still in the room a week later — the reason
[Nomi](/reviews/nomi-review/) scores where it does — are the ones that manage
the middle two well. Nothing about that is visible from a pricing page, which
is why it takes weeks rather than an afternoon to judge.

## What "AI girlfriend" covers in practice

The label stretches across products that barely resemble each other:

- **Companion apps** built for a continuing relationship, with journaling and
  mood tracking. [Replika](/reviews/replika-review/) is the oldest example.
- **Roleplay apps** built for scenes and stories rather than continuity.
- **Media-first apps** where the chat exists to produce images and voice, and
  [Candy AI](/reviews/candy-ai-review/) sits closest to the middle by doing all
  three inside one subscription.

Deciding which of the three you actually want removes about half the apps on
any list, including [ours](/best-ai-girlfriend-apps/), before price enters the
conversation.

## What none of them are

They do not know you between sessions unless they were built to store facts and
retrieve them. They do not have continuity of self — the character is recreated
from text at the start of every turn. And they are not private by default: the
conversation is processed on someone's servers, which is the subject of
[what these apps know about you](/articles/what-your-ai-girlfriend-app-knows/).
