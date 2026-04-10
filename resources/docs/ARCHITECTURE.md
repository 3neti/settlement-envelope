# Settlement Envelope — Architecture & Developer Guide

This document explains how the Settlement Envelope system works **within the broader x-change ecosystem**, and provides guidance for developers and AI agents extending the system.

---

## 🧭 System Role in x-change

Settlement Envelope is the **policy and gating layer**.

It determines whether a transaction (voucher redemption, disbursement, etc.) is allowed to proceed.

### High-level flow

```text
Voucher → Contact → Inputs → Envelope → Cash → Wallet → Settlement
```

### Responsibilities

| Component | Role |
|----------|------|
| contact | identity (who) |
| model-input | dynamic attributes (what user provided) |
| settlement-envelope | validation + gating (can we proceed?) |
| cash | monetary value |
| wallet | ledger / balance |
| voucher | transaction instruction |

---

## 🧠 Mental Model

Settlement Envelope is a **policy-driven state machine**.

It answers:

> ❓ Is this transaction allowed to settle?

Based on:

- payload (structured data)
- attachments (evidence)
- signals (external truth)
- driver rules (policy)

---

## 🧱 Core Architecture

```text
Driver (YAML policy)
    ↓
Payload (JSON, versioned)
    ↓
Checklist (derived requirements)
    ↓
Attachments (evidence)
    ↓
Signals (external state)
    ↓
Gate Evaluation (rules engine)
    ↓
Settlement Decision
```

---

## 🔑 Core Principles

### 1. Drivers define behavior

All business rules live in YAML drivers.

PHP code must remain **generic and reusable**.

---

### 2. Envelope stores state, drivers define logic

| Layer | Responsibility |
|------|---------------|
| Envelope (DB) | state |
| Driver (YAML) | rules |

Never mix the two.

---

### 3. Gates are pure

A gate must behave like:

```text
gate = f(payload, checklist, signals)
```

- no side effects
- no database writes
- deterministic

---

### 4. Attachments are not truth

Uploading ≠ valid

Only **reviewed/accepted** attachments count.

---

### 5. Signals are external truth

Examples:
- KYC approved
- account created
- AML passed

They must come from external systems.

---

## ⚙️ Extension Points

### Add a new driver

```text
config/envelope-drivers/*.yaml
```

Do not change PHP for business rules.

---

### Add a new gate rule

Modify:

```text
GateEvaluator
```

Rules:
- pure logic only
- no I/O
- no persistence

---

### Add new payload validation

Handled by:

```text
PayloadValidator
```

Uses JSON Schema.

---

### Add document types

Defined in driver:

```yaml
documents:
  registry:
    - type: "ID_DOCUMENT"
```

---

## 🔗 Integration with Other Packages

### Contact (identity layer)

Provides:
- mobile
- KYC status
- bank info

Envelope may depend on:
- KYC signals
- identity validation

---

### Model Input (dynamic attributes)

Provides:
- user-provided values (mobile, signature, etc.)

Envelope may:
- validate inputs via payload
- enforce required fields

---

### Voucher (instruction layer)

Voucher:
- creates envelope
- references envelope state

---

### Cash (value layer)

Cash represents:
- amount to be disbursed

Envelope must approve before:
- cash is used

---

### Wallet (ledger layer)

Final execution happens here.

Envelope must be:
```text
settleable = true
```

before wallet operations.

---

## 🚫 Anti-Patterns

### ❌ Hardcoding rules in PHP

Bad:
```php
if ($amount > 1000000) { ... }
```

Good:
```yaml
checklist:
  - key: "large_amount_check"
```

---

### ❌ Bypassing EnvelopeService

Always use:

```php
EnvelopeService
```

---

### ❌ Mixing policy with persistence

Keep:
- drivers = rules
- models = state

---

## 🧪 Testing Strategy

- use driver fixtures
- test gate evaluation
- test payload validation
- minimize DB reliance

---

## 🗃️ Migration Policy

- migrations loaded via `loadMigrationsFrom()`
- package owns runtime schema
- tests use SQLite in-memory
- no manual migration execution

---

## 🧭 Design Philosophy

- policy-driven (YAML over PHP)
- composable (drivers can evolve)
- auditable (state transitions traceable)
- deterministic (same input → same result)

---

## 🚀 Future Evolution

- driver version migrations
- explainable gate outputs
- multi-envelope orchestration
- audit logs per gate evaluation

---

## 🧠 Final Guidance

Before making changes, ask:

> “Am I encoding business logic… or enabling the system to express it?”

If you are encoding logic in PHP, you are likely doing it wrong.

---

End of Architecture Guide.
