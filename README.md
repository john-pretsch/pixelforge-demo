# Pixelforge Studio

## What is it
A Laravel 12 + Vue 3 + Inertia app that generates images through **Pixelforge**, a simulated asynchronous image API. Users see an estimated price before they submit and get live status updates. 

## How was it built

This was generated using Claude Code / Opus 5.5. The API docs and Assessment doc were used as part of the prompting:

https://pixelforge.promptedit.dev/assessment
https://pixelforge.promptedit.dev/docs

The code was generated using both planning mode, manual mode and automatic mode. Git diff was used to view the generated addions and changes. This allowed some granularity in the commit history.

## Now What?

At first glance the code looks ok and the basic functionality works, but that is usually the case with generated code. The tricky part is going from this to an application that can be submitted with confidence. 

### Static Analysis

Let's start with some low hanging fruit and run some static analysis on the generated code. This will catch syntax and other 

- **Larastan (level 6):** 10 issues, all in the generated code. Most were missing type annotations; there was also one redundant `array_values()` call. The fixes change no behaviour. It now reports 0 errors with no baseline.
- **ESLint:** came with the starter kit and reported 0 problems.

```bash
composer analyse      # Larastan
npm run lint:check    # ESLint (report only)
```

### Dependency Verification
This is an area where both human and AI agents often slip up. npm audit found many issues. The below command fixes non breaking changes. If this was a production app, I would include a thorough analysis of this in my devsec hardening plan.

```bash
    npm audit fix #fix 
```
