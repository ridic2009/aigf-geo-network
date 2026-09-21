---
title: What Happens to the Images an AI Companion Generates
slug: ai-generated-images-privacy
status: published
type: article
category: privacy
translation_key: generated-images
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  Every picture you generate exists on someone else's storage, usually behind a
  URL that does not check who is asking, and frequently outside whatever your
  deletion request covers.
products:
  - candy-ai
  - secretdesires
faq:
  - question: Are generated images private to my account?
    answer: >
      The gallery is. The file often is not — many apps serve images from a
      content network on a long unguessable URL, which anyone holding the link
      can open.
  - question: Does deleting my account delete the images?
    answer: >
      Not always. Images frequently live in separate storage from conversations
      and are not always covered by the same request. Ask explicitly.
  - question: Can I upload a photo of a real person?
    answer: >
      Do not. Every serious operator prohibits it, it is illegal in a growing
      number of places, and it is the fastest way to lose an account
      permanently.
related:
  - articles/how-media-works
  - articles/what-the-app-knows
  - articles/deleting-your-account
seo:
  title: AI Companion Image Privacy — Where Your Pictures Actually Live
  description: How generated images are stored and served, why the URLs are often public, and what a deletion request does and does not remove.
  primary_keyword: ai generated image privacy
indexing:
  index: true
  follow: true
---
The chat is the part people worry about. The images are the part that is more
exposed, more persistent, and least covered by the policies people actually
read.

## Where the file goes

When an app generates a picture, it is written to object storage and served
through a content delivery network — the same infrastructure any site uses for
images. Your gallery is a list of those files.

The important consequence is about **how the file is protected**. There are two
approaches and the difference matters:

**Signed or authenticated URLs.** The link expires, or the server checks your
session before serving the file. Copy the URL, open it elsewhere, and you get
nothing.

**Long unguessable URLs.** The file sits at an address nobody could reasonably
guess, and anyone holding the address can open it, logged in or not, forever.

The second is extremely common because it is cheap and fast. It is not
unreasonable — the address genuinely is unguessable — but it means the image is
protected by secrecy of the link rather than by access control. A link shared,
pasted into a chat, or captured by a browser extension is a public link.

**How to check in ten seconds:** open a generated image in a new tab, copy the
address, and paste it into a private window. If it loads, the file is protected
by obscurity alone. Worth knowing before you generate anything you would mind
being detached from your account.

## Why deletion often misses them

Conversations and images are usually stored in different systems. A deletion
request implemented against the conversation database does not necessarily reach
object storage, and the CDN may hold cached copies for a while after the origin
file is gone.

None of this is necessarily bad faith. It is what happens when deletion is built
against the primary database and images live somewhere else.

The practical move, when you close an account, is to ask explicitly: *does this
include generated images, and how long until they are removed from storage and
cache?* The full sequence is in
[deleting an AI companion account](/articles/deleting-an-ai-companion-account/).

## Moderation keeps copies

Images are scanned, automatically and sometimes by people. Anything flagged is
typically retained longer than ordinary content — often specifically excluded
from deletion, because retaining it is how the operator demonstrates compliance.

This is normal, it is disclosed in most policies, and it means a picture that
tripped a filter is the one most likely to outlive your account.

## The two hard rules

**Never upload a photo of a real person.** Not a partner, not a celebrity, not
someone from a dating app. Every credible operator prohibits it; an increasing
number of jurisdictions criminalise generating intimate imagery of an
identifiable person without consent; and it is the single fastest route to a
permanent ban with no appeal.

This is also where the technical and the ethical point in the same direction:
the person in the photo has not agreed to be in your chat log, and
[the chat log is stored](/articles/what-your-ai-girlfriend-app-knows/).

**Assume the prompt is stored with the image.** The text you wrote to produce a
picture is usually retained alongside it, and it is far more revealing than the
picture. People are careful about images and careless about prompts.

## If you want to keep them

Download what you want and treat the gallery as temporary. Apps reorganise
storage, change plans, expire media for inactive accounts, and occasionally lose
things.

Then store them somewhere you control, and think about where that is — a photo
library that syncs to a family account, or auto-uploads to a shared cloud drive,
has simply moved the exposure rather than removed it.

## What this means when choosing

Two things worth checking in a free tier, alongside
[the consistency and latency tests](/articles/how-ai-companion-media-works/):

- Whether image URLs are authenticated, using the private-window test above.
- Whether the app offers a bulk download or an export that includes media.

Apps that put real work into the media side — [Candy AI](/reviews/candy-ai-review/)
with images and voice inside one subscription,
[Secret Desires](/reviews/secret-desires-review/) with image and short video
generation — are the ones you will accumulate the most files with. That makes
the ten-second URL check more worth doing there, not less.
