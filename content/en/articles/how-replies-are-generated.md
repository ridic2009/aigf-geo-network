---
title: How an AI Companion Actually Writes Its Reply
slug: how-ai-companions-generate-replies
status: published
type: article
category: basics
translation_key: how-replies-are-generated
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  One word at a time, with a deliberate dice roll between each. Understanding
  that single mechanism explains why the same question gives different answers,
  why long replies drift, and why "regenerate" so often works.
products:
  - candy-ai
  - nomi
faq:
  - question: Why does the same question give different answers?
    answer: >
      Because the next word is sampled from a probability distribution rather
      than picked deterministically. The randomness is a setting, and it is
      what makes the character feel alive rather than canned.
  - question: What does "regenerate" actually do?
    answer: >
      It reruns the same prompt with a new random seed. Nothing about the
      character changed - you are drawing a second sample from the same
      distribution.
  - question: Why do long replies wander off?
    answer: >
      Each word is conditioned on the ones before it, so a small drift early
      compounds. By paragraph three the reply is mostly responding to itself
      rather than to you.
related:
  - articles/what-is-an-ai-girlfriend
  - articles/hallucinations
  - articles/how-memory-works
seo:
  title: How AI Girlfriend Apps Generate Replies - The Mechanism
  description: Tokens, sampling and temperature explained without maths - and how each one causes a specific behaviour you have already noticed.
  primary_keyword: how ai girlfriend apps work
indexing:
  index: true
  follow: true
---
Almost every complaint about these apps - the repetition, the drift, the
uncanny inconsistency between two runs of the same scene - comes from one
mechanism. It is worth ten minutes because it turns "the app is broken" into
"the app is doing the thing it does, and here is the setting."

## It writes one piece at a time

The model does not compose a reply and then type it. It produces one token -
roughly a word or part of a word - then reads everything including that token
and produces the next.

That is the whole loop. There is no plan, no outline, no draft being revised.
A reply that ends beautifully did not know it would when it started.

Two consequences follow immediately, and both are things you have seen:

**A reply cannot un-commit.** Once the model has written "I have never been to
Paris," everything after is conditioned on that. It will build consistency
around a sentence it produced by accident rather than contradict itself.

**Errors compound forwards.** A slightly-off word in sentence one nudges
sentence two, which nudges sentence three. This is why long replies drift and
short ones rarely do.

## The dice roll between each word

At every step the model has a ranked list of candidates with probabilities:
maybe `"good"` at 30%, `"fine"` at 12%, `"terrible"` at 3%, and a long tail.

If it always took the top candidate, the character would be deterministic and
extremely boring - the same greeting every time, the same three jokes. So the
app samples instead: it rolls weighted dice.

The weighting is controlled by a setting usually called **temperature**. Low
temperature concentrates the probability on the obvious candidates: consistent,
predictable, eventually dull. High temperature flattens the distribution: more
surprising, more creative, and more likely to produce something that does not
follow.

You rarely get a slider for this. What you get is an app's chosen point on that
trade-off, and it is a large part of what people mean when they say one app
"feels smarter" than another. It is not always smarter. Sometimes it is just
warmer or colder.

This is also the honest answer to "why did regenerating fix it?" Nothing was
fixed. You drew again from the same distribution and got a better roll.

## What the model is actually looking at

Before your message, the app assembles a block of text you never see: the
character description, the facts it has stored about you, a summary of your
history, and the recent conversation. That whole assembly is
[the context window](/articles/ai-companion-memory-explained/), and the reply is
generated from it as a single continuous document.

This has a practical implication most people never exploit: **the model responds
to the shape of what it can see.** Give it three short, flat messages and it
will produce short, flat replies, because that is the pattern it is continuing.
Give it a vivid message and the register shifts. You are not persuading a
person, you are setting a pattern, and the pattern is contagious in both
directions.

It also explains the most common self-inflicted problem in this category. People
settle into "hey", "how was your day", "what are you doing" - and then conclude
the app got worse. The app is continuing the document you are writing together.

## Why apps sound different when the model is the same

Several apps in this category run on similar underlying models. They still feel
distinct, and the differences come from things layered around the model:

- **The system prompt** - how the character is described, at what length, with
  what instructions about tone and pacing.
- **The sampling settings** - the temperature point discussed above.
- **What gets retrieved** - which facts and which slice of history are put in
  front of the model for this particular turn.
- **The filter** - what gets refused, and whether a refusal is graceful or a
  wall.

[Nomi](/reviews/nomi-review/) reads as consistent over weeks because of the
third item, not because of a bigger model.
[Candy AI](/reviews/candy-ai-review/) covers chat, images and voice in one
subscription, which is a product decision rather than a modelling one. Neither
difference would show up in a benchmark, and both show up within a fortnight of
use, which is [how we score them](/guides/how-we-test/).

## Four things this lets you do

**Steer early, not late.** The first two sentences of a reply determine the
rest. If a scene is going wrong, stop it and restate, rather than arguing with
paragraph four.

**Use regenerate deliberately.** If a reply is 80% right, regenerating throws
away the 80%. If it is wrong in its first sentence, regenerate immediately.

**Write the register you want back.** Short and flat gets short and flat. This
is the single cheapest improvement available to anyone who thinks their
companion has become boring.

**Do not argue about facts it invented.** A model that has written something
false will defend it, because consistency with its own output is what it is
built to do. Correcting it in a new message works; demanding it admit the error
does not. That behaviour has its own article:
[why AI companions invent things](/articles/ai-companion-hallucinations/).

## The part worth remembering

There is no one home between your messages. The character exists for the
duration of a single generation and is rebuilt from text at the start of the
next one. Every impression of continuity is an achievement of the plumbing
around the model - the stored facts, the summaries, the retrieval - and that
plumbing is what actually differs between a $10 app and a $20 one.

Which is why [our ranking](/#ranking) weighs memory and
consistency as heavily as it does. They are the parts a landing page cannot
show you.
