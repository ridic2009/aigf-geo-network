---
title: Using an AI Companion on a Shared Device
slug: ai-companion-shared-device
status: published
type: article
category: privacy
translation_key: shared-device
date: 2026-09-21
updated: 2026-09-21
author: m-keller
reviewer: s-moreau
intro: >
  A shared laptop, a family tablet, a phone that gets handed over. The leaks
  here are boring and mechanical — autocomplete, notifications, synced tabs —
  and every one of them has a two-minute fix.
products:
  - camsoda-ai
  - joi
faq:
  - question: Does private browsing actually help?
    answer: >
      For history and cookies, yes. It does nothing about synced tabs, saved
      passwords, downloads, DNS caches or notifications, which is where most
      people are actually caught.
  - question: Is a browser-only app safer on a shared device?
    answer: >
      Usually, because nothing sits on the home screen. The trade-off is being
      logged out often, which is covered in our piece on web versus native.
  - question: What leaks most often?
    answer: >
      Address-bar autocomplete, in a tie with lock-screen notifications. Both
      appear in front of someone who was not looking for anything.
related:
  - articles/payment-privacy
  - articles/web-vs-native
  - articles/account-security
seo:
  title: Using an AI Girlfriend App on a Shared Computer or Phone
  description: The mechanical ways these apps surface on shared devices — autocomplete, sync, notifications, downloads — and the fix for each.
  primary_keyword: ai girlfriend private browsing
indexing:
  index: true
  follow: true
---
Nothing here is sophisticated. Nobody is being hacked. People are caught by
autocomplete and by notification previews, which is why the fixes are
mechanical and take about ten minutes in total.

## The eight places it actually surfaces

**Address bar autocomplete.** Type two letters, the browser offers the site. The
most common leak by a distance, and it survives clearing history if the site is
bookmarked or has a saved password.

**Lock-screen notifications.** From the app, or from your bank about the charge.
Previews show the sender and the first line to anyone in the room.

**Synced tabs and history.** Chrome, Safari and Firefox sync across devices on
the same account. An open tab on your laptop is visible on a family iPad signed
into the same account. Private browsing does not stop this because the tab is
not private — it is on your normal profile.

**Saved passwords.** The autofill list is a list of the sites you use, readable
by anyone with an unlocked device.

**Downloads folder.** Generated images land there with descriptive filenames.

**Photo library sync.** A downloaded image on a phone can land in a library that
backs up to a shared family album.

**App store purchase history.** Family Sharing surfaces purchases to other
members.

**Keyboard learning.** Phone keyboards learn the words you type and then suggest
them in other apps, including messaging ones. This one genuinely surprises
people.

## The fixes, in order of value

### Use a separate browser profile

The single highest-value change, and it takes two minutes. A second Chrome or
Firefox profile — signed out, or signed into a separate account — gets its own
history, its own autocomplete, its own saved passwords and its own sync.

This solves autocomplete, sync, saved passwords and most of the rest in one
move, and unlike private browsing it stays logged in, so it is actually
sustainable.

### Turn off notification previews

System-wide is easiest: iOS Settings → Notifications → Show Previews → When
Unlocked; Android has the equivalent under notification settings. Do it for your
banking app too, for the reasons in
[payment privacy](/articles/ai-girlfriend-payment-privacy/).

### Do not save the password in the shared profile

Keep it in a password manager behind its own lock, not in the browser's autofill
on a device other people use.

### Change the download location

Point image downloads somewhere that is not the default Downloads folder, and
keep it out of any folder that syncs.

### Check keyboard learning

On iOS, Reset Keyboard Dictionary clears learned words. On Android it is under
the keyboard's personalisation settings. Worth doing once after a few weeks of
use.

## Private browsing is not the answer

It is worth being precise about this, because people rely on it and it is only
partial.

Private browsing prevents history, cookies and cached files persisting on that
device. It does **not** prevent synced tabs, saved passwords, downloads,
notifications, keyboard learning or anything at the network level. It also logs
you out every session, which for a browser-only app means re-authenticating
constantly.

A separate profile does more and costs less friction.

## Why browser-only can be the safer choice

[Camsoda AI](/reviews/camsoda-ai-review/) and [Joi](/reviews/joi-review/) run
only in a browser, and [Secret Desires](/reviews/secret-desires-review/) in a
mobile browser. On a shared phone that is a genuine advantage: no icon, no app
in the app switcher, no entry in purchase history.

The cost is session persistence — browsers clear cookies and iOS evicts site
data aggressively, so expect to log in often. The full trade-off is in
[browser or app store](/articles/web-app-vs-native-ai-companion/).

## If the device is not yours

A work laptop is a different category entirely. Assume network monitoring,
assume managed-device software can see browsing regardless of private mode, and
assume IT can read it. No browser setting changes any of that.

The only correct answer on employer hardware is not to. That is not a privacy
tip, it is an employment one.

## The honest floor

None of this protects you from someone with your unlocked device and an interest
in looking. It protects you from the accidental case — the glance at a lock
screen, the shared tab, the autocomplete — which is what actually happens.

Pair it with the account-side basics in
[account security](/articles/ai-companion-account-security/) and you have
covered the realistic risks.
