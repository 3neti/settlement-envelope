# How to Create a Driver

This guide explains how to create a **Settlement Envelope Driver** — a YAML-based policy that defines how a transaction is validated and allowed to settle.

---

# 🧠 Mental Model

A driver is a **declarative policy engine**.

It answers:

> ❓ *What must be true before this transaction can settle?*

The system evaluates:

```text
payload → checklist → signals → gates → settleable
```

---

# 🧱 Driver Anatomy

```yaml
driver:
  id: "my.driver"
  version: "1.0.0"
  title: "My Driver"

payload:
  schema: ...

documents:
  registry: ...

checklist:
  template: ...

signals:
  definitions: ...

gates:
  definitions: ...
```

---

# 🔄 Execution Flow (IMPORTANT)

Understanding this flow is key:

```text
1. Payload is validated (JSON schema)
2. Checklist is evaluated
   - payload fields
   - documents
   - signals
3. Attachments are reviewed (if required)
4. Signals are injected (external truth)
5. Gates are evaluated
6. settleable = true/false
```

👉 If something fails, **trace it backward from gate → checklist → source**

---

# 🪪 Driver Metadata

```yaml
driver:
  id: "bank.home-loan"
  version: "1.0.0"
  title: "Bank Home Loan"
  description: "Loan disbursement requirements"
  domain: "housing_finance"
  issuer_type: "developer"
```

### Rules

- `id` must be globally unique
- `version` must be immutable once deployed
- do NOT reuse IDs for different logic

---

# 📦 Payload (Structured Data)

Defines what structured data is required.

```yaml
payload:
  schema:
    id: "bank.home-loan.v1"
    format: "json_schema"
    inline:
      type: object
      required: ["borrower"]
      properties:
        borrower:
          type: object
          properties:
            full_name:
              type: string
```

### Best Practices

- Keep payload minimal and structured
- Use nested objects (not flat keys)
- Always define `required`

---

# 📄 Documents (Evidence Layer)

```yaml
documents:
  registry:
    - type: "BORROWER_ID"
      title: "Borrower ID"
      allowed_mimes: ["image/jpeg", "application/pdf"]
      max_size_mb: 5
      multiple: false
```

### Key Ideas

- Documents are **evidence**, not truth
- Validation happens via checklist + review

---

# ✅ Checklist (Requirements Engine)

Checklist converts rules into **verifiable items**.

```yaml
checklist:
  template:
    - key: "name_provided"
      kind: "payload_field"
      payload_pointer: "/borrower/full_name"
      required: true

    - key: "id_uploaded"
      kind: "document"
      doc_type: "BORROWER_ID"
      required: true
      review: "required"

    - key: "kyc_verified"
      kind: "signal"
      signal_key: "kyc_passed"
```

### Kinds

| kind | source |
|------|--------|
| payload_field | payload JSON |
| document | uploaded files |
| signal | external system |

---

# 📡 Signals (External Truth)

```yaml
signals:
  definitions:
    - key: "kyc_passed"
      type: "boolean"
      default: false
```

### Rules

- Signals must come from external systems
- Do NOT simulate them via payload
- Use signals for:
  - KYC
  - AML
  - account creation
  - approvals

---

# 🚪 Gates (Decision Engine)

```yaml
gates:
  definitions:
    - key: "settleable"
      rule: "checklist.required_accepted && signal.approved"
```

### Gate Rules

- must be **pure boolean expressions**
- must not mutate state
- must be deterministic

---

# 🧪 Minimal Working Driver

```yaml
driver:
  id: "simple.test"
  version: "1.0.0"
  title: "Simple Test Driver"

payload:
  schema:
    id: "simple.test.v1"
    format: "json_schema"
    inline:
      type: object
      required: ["name"]
      properties:
        name:
          type: string

documents:
  registry:
    - type: "ID_DOC"

checklist:
  template:
    - key: "name_provided"
      kind: "payload_field"
      payload_pointer: "/name"
      required: true

    - key: "id_uploaded"
      kind: "document"
      doc_type: "ID_DOC"
      required: true

signals:
  definitions:
    - key: "approved"
      type: "boolean"
      default: false

gates:
  definitions:
    - key: "settleable"
      rule: "checklist.required_accepted && signal.approved"
```

---

# 🧠 Design Patterns

## 1. Split responsibility

- payload → data  
- checklist → requirements  
- signals → external truth  
- gates → decision  

---

## 2. Prefer checklist over gates

Bad:
```yaml
rule: "payload.name && signal.approved"
```

Good:
```yaml
checklist:
  - key: "name_provided"
```

---

## 3. Use signals for async processes

- KYC  
- approvals  
- external API calls  

---

# 🧪 Debugging Guide

When something is not settleable:

```text
1. Check gate result
2. Check checklist status
3. Check missing requirement
4. Check source (payload/document/signal)
```

---

# 🚫 Anti-Patterns

❌ Hardcoding rules in PHP  
❌ Using payload instead of signals  
❌ Treating uploads as validation  
❌ Skipping checklist layer  
❌ Overloading gates with logic  

---

# 🧭 Versioning Strategy

- Always bump version for breaking changes  
- Never mutate an existing version  
- Use version to migrate policies safely  

---

# 🧠 Final Rule

> If you are writing business logic in PHP…  
> you are probably doing it wrong.

Drivers define policy.  
The system executes it.

---

End of Guide.
