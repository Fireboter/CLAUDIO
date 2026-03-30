# Multi-Model Chained Generation — Design

## Goal
Replace single-call content generation with a 3-call chained approach, support Claude Haiku and GPT-4.1 nano interchangeably, and add a model switcher to the admin dashboard.

## Architecture
A `AIProvider` interface abstracts API transport. `ClaudeProvider` and `OpenAIProvider` implement it. `ProviderFactory` reads the active model from a `settings` DB table and returns the right provider. `ContentGenerator` receives a provider and only handles prompt logic + chaining.

## Key Decisions
- **Chain:** outline → content → meta (3 calls per page)
- **Variation pages:** full independent article (1000-1500 words), variation value woven throughout, not just a paragraph
- **Model scope:** global — one active model affects all generation
- **Budget tracking:** per `api_name` (`claude` or `openai`) in existing `api_usage` table
- **Model switcher:** dropdown top-right of admin header, GET/POST `/admin/api/model.php`

## Chain Structure
```
Call 1 — Outline   (~300 tokens out)
  Input:  page context (rechtsfrage, rechtsgebiet, variation type+value if applicable)
  Output: JSON { sections: [{title, points:[...]}, ...] }

Call 2 — Content   (~2000 tokens out)
  Input:  outline JSON + same context
  Output: full HTML body, variation value woven throughout

Call 3 — Meta      (~200 tokens out)
  Input:  generated title candidate + first paragraph
  Output: JSON { title, meta_description, meta_keywords, og_title, og_description }
```
