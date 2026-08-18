# Dana — Overview

> **Status:** DRAFT — pending answers in [02-OPEN-QUESTIONS.md](02-OPEN-QUESTIONS.md)
> **Last updated:** 2026-08-04

## What this is

A mobile app (iOS + Android, Flutter) for **Dana**, an English language
learning centre operating multiple branches across Turkmenistan. The app
serves **students** and **teachers**. **Admins** and the **superadmin**
work in separate web panels.

Interface languages: **Turkmen** and **Russian**. Learning content is
English; all instructions, grammar explanations and question prompts are
authored in both TM and RU.

## Roles

| Role | Where | Created by | Can do |
|---|---|---|---|
| **Superadmin** | Web panel | System (bootstrap) | Everything. Creates admins. Owns all learning content (levels, books, units, vocabulary, grammar, exercises). Push to all users. |
| **Admin** | Web panel | Superadmin | Scoped to **one centre**. Creates teachers. Creates classrooms (teacher + level + book). Sees all student progress in the centre. Can change any student login/password in the centre. Push to centre's students. |
| **Teacher** | Mobile app | Admin | Scoped to **own classrooms**. Creates student logins/passwords per classroom. Unlocks unit sections as taught. Sees progress + leaderboard. Can change own students' credentials. |
| **Student** | Mobile app | Teacher | Does exercises, earns points, reads grammar + vocabulary for unlocked sections, sees classroom leaderboard. **Cannot change own credentials.** |

All accounts are **pre-created with login + password**. There is no
self-registration and no email-based signup anywhere in the system.

## Content hierarchy

```
English
└── Level            (Beginner … Advanced — superadmin can add/rename/delete)
    └── Book         (Student's Book, Workbook — uploaded by superadmin)
    └── Unit         (Unit 1, Unit 2, …)
        └── Section  (1A, 1B, 1C … — the unit of unlocking and of content)
            ├── Vocabulary      (entered manually by superadmin)
            ├── Grammar         (AI-simplified from the book, TM + RU)
            └── Exercise sets   (AI-generated from the book's pages)
                └── Questions   (bilingual TM/RU, editable by superadmin)
```

**Section (`1A`) is the atomic unit of the whole system.** Unlocking,
progress, grammar, vocabulary and exercises are all keyed to it.

## Core mechanics

- **Unlocking is per classroom, teacher-driven.** Teacher marks 1A as
  taught → 1A opens for that classroom. Starts 1B → 1B also opens.
  Cumulative; nothing locks again.
- **Points:** `+5` first-time correct, `+3` first-time incorrect.
  Awarded **once per question, ever**.
- **Incorrect questions repeat** inside the exercise until answered
  correctly. Repeats award nothing.
- **Points are committed to the server only when the exercise set is
  completed.** An abandoned set awards nothing.
- **Leaderboard** ranks students within a classroom.

## Document index

| File | Contents |
|---|---|
| [00-OVERVIEW.md](00-OVERVIEW.md) | This file. |
| [01-REQUIREMENTS.md](01-REQUIREMENTS.md) | Numbered functional requirements (`FR-*`). The contract. |
| [02-OPEN-QUESTIONS.md](02-OPEN-QUESTIONS.md) | **Every unresolved decision.** Answer these before build. |
| [03-ARCHITECTURE.md](03-ARCHITECTURE.md) | Stack, deployment, repo layout. |
| [04-DATA-MODEL.md](04-DATA-MODEL.md) | MySQL schema. |
| [05-CONTENT-PIPELINE.md](05-CONTENT-PIPELINE.md) | AI generation rules, constraints, validation gates. |
| [06-DESIGN-SYSTEM.md](06-DESIGN-SYSTEM.md) | Tokens, typography and component specs measured from Figma. |

## Conventions used in these docs

- `FR-*` = functional requirement. Referenced from code and commits.
- `Q-*` = open question. Nothing marked `Q-*` may be implemented by
  assumption — it must be answered first.
- **DRAFT** in a header = written from the brief, not yet confirmed.
