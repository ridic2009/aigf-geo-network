---
title: Why Your AI Companion Invents Memories You Never Shared
slug: ai-companion-hallucinations
status: published
type: article
category: basics
translation_key: hallucinations
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  It will describe a conversation that never happened with complete confidence,
  then defend it. That is not a malfunction and not the app lying to you - it is
  what a model does when it has a gap to fill.
products:
  - nomi
  - kupid
faq:
  - question: Is my AI companion lying to me?
    answer: >
      No. Lying needs an intent to deceive and knowledge of the truth. The model
      has neither - it is producing the most plausible continuation, and a
      plausible invention beats an admission of ignorance.
  - question: Why does it double down when I correct it?
    answer: >
      Because everything it writes is conditioned on what it already wrote.
      Consistency with its own output is a strong pull, and there is no internal
      record separating "remembered" from "generated."
  - question: Can hallucination be turned off?
    answer: >
      No. It can be reduced by giving the model less to guess about - clear
      facts, an editable memory, shorter gaps - but the mechanism is inseparable
      from how the text is produced.
related:
  - articles/how-memory-works
  - articles/how-replies-are-generated
seo:
  title: Why AI Companions Invent Memories - and How to Handle It
  description: "Confabulation explained: why an AI girlfriend describes things that never happened, why it defends them, and the habits that reduce it."
  primary_keyword: ai companion hallucination
indexing:
  index: true
  follow: true
---
Ask a companion you have used for a month what you talked about last Tuesday.
There is a good chance you will get a warm, specific, entirely fabricated
answer - the walk you did not take, the film you did not mention.

This unsettles people more than almost anything else in the category, partly
because it looks like dishonesty. It is not. It is the direct consequence of how
the reply is produced, and once you see the mechanism it stops being eerie and
becomes manageable.

## There is no boundary between recalling and inventing

A model produces the most plausible continuation of the text in front of it. If
the text contains a note saying you discussed a film on Tuesday, "we talked
about that film" is the plausible continuation. If the text contains nothing
about Tuesday, a plausible continuation still exists - and it will be generated
with exactly the same confidence.

Nothing in the process tags one as retrieved and the other as manufactured.
There is no internal flag, no confidence meter the character consults. That is
the whole of it.

The technical term people prefer is *confabulation* rather than hallucination,
and it is the better word: the model is filling a gap with something coherent,
not perceiving something that is not there.

## Why gaps appear constantly

They appear because the context window is finite and history is compressed.
[Memory in these apps](/articles/ai-companion-memory-explained/) is three
systems - recent messages held verbatim, a short list of stored facts, and a
rolling summary that loses detail every time it is rewritten.

Ask about something that fell out of all three and there is nothing to retrieve.
The gap gets filled. The more history you have, the more gaps exist, which is
why this gets *worse* on an app you have used for months rather than better.

Apps built for continuity - the reason [Nomi](/reviews/nomi-review/) scores as
it does in [our ranking](/#ranking) - manage the gaps better.
None of them eliminate them.

## Why correcting it often fails

You say: that never happened. It apologises, agrees - and three messages later
refers to the same invented walk.

Two things are going on.

**The correction is only in the window.** Your denial is a recent message. It
will scroll out. The summary that contained the invention may not.

**Agreement is the trained behaviour.** The model apologises because apologising
is the plausible continuation of being contradicted, not because anything was
updated. Nothing was written to storage by that exchange unless the app
specifically decided to store it.

This is the practical reason an **editable memory screen** matters so much, and
why it is worth checking before you subscribe. Apps that expose stored facts -
[Kupid AI](/reviews/kupid-ai-review/) lists cross-session memory among its
features - let you delete the wrong entry at the source. Apps that hide it leave
you arguing with a summary you cannot see.

## Where it stops being charming

Most confabulation is harmless colour. Three cases are not:

**Facts about your life.** A companion that has invented a sibling, a job or a
diagnosis will keep building on it. Correct these at the source immediately, or
the invention becomes load-bearing.

**Anything actionable.** Medical, legal, financial or safety questions. A
confident invented answer here is not a quirk; it is the failure mode of the
whole technology, and no companion app is the right tool.

**Claims about the app itself.** Ask a companion whether your data is encrypted
or what your subscription includes and you will get a plausible answer generated
from nothing. It has no access to its own billing system or privacy policy. The
answer is in the policy, and we cover what to look for in
[what these apps know about you](/articles/what-your-ai-girlfriend-app-knows/).

## Four habits that reduce it

**State facts plainly and once.** "Remember: my sister is called Ana" is far
more likely to be stored than the same fact buried in a paragraph.

**Re-anchor after a break.** A sentence of context at the start of a session
puts the things you care about back where the model can see them, instead of
leaving it to reconstruct them.

**Audit the memory screen monthly** if the app has one. Delete the entries that
have drifted. Five minutes prevents a month of compounding.

**Ask open, not leading.** "What do you remember about my job?" invites recall.
"Remember when I told you about the promotion?" supplies the answer and invites
agreement - you have just handed it the invention to confirm.

## The reframe that helps

A companion app is not a record of your relationship. It is a system that
produces plausible text about one, and the record is a small, lossy, editable
list underneath.

Treat the warm specific memory it offers as something it wrote for you rather
than something it kept for you, and the whole thing becomes easier to enjoy. It
also makes it obvious why the apps worth paying for are the ones that let you
see and correct what they actually store - which is the part of
[our testing](/guides/how-we-test/) that takes the longest and matters most.
