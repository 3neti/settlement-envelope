# Anatomy of philhealth-bst Driver (Aligned with Settlement Envelope Architecture)

This document explains the **philhealth-bst.yaml** driver and aligns it with the official
Settlement Envelope architecture (see ARCHITECTURE.md).

---

## 🧭 Role in the System

Within x-change:

```text
Voucher → Contact → Inputs → Envelope → Cash → Wallet → Settlement
```

The **philhealth-bst driver** defines the **policy layer** for healthcare reimbursements.

👉 It answers:
> “Is this PhilHealth claim ready to be settled?”

---

## 🧠 Architectural Mapping

| Driver Section | Architecture Layer | Purpose |
|---------------|------------------|--------|
| schema.payload | Payload | Structured data |
| documents | Attachments | Evidence |
| checklist | Checklist | Requirements |
| gates | Gate Evaluation | Decision |
| form_flow_mapping | Integration | Data hydration |
| audit | Observability | Compliance |

---

## 🧱 Driver Metadata

```yaml
id: philhealth-bst
version: 1.0.0
name: PhilHealth BST Settlement
description: Benefit Support Token workflow for PhilHealth reimbursements
```

### Architectural Role

- Identifies **policy version**
- Enables **safe evolution of rules**
- Binds envelope → driver

---

## 📦 Payload Layer (Schema)

```yaml
schema:
  payload:
    required: true
```

### Interpretation

This deviates from strict JSON Schema and behaves like:

👉 **Form + validation hybrid layer**

### Architectural Mapping

```text
Payload = source of structured truth
```

---

## 📄 Documents (Evidence Layer)

```yaml
documents:
  CLAIM_FORM:
```

### Architectural Role

```text
Documents = evidence (NOT truth)
```

| Type | Role |
|------|------|
| CLAIM_FORM | Required compliance |
| HOSPITAL_BILL | Financial proof |
| DISCHARGE_SUMMARY | Medical proof |
| VALID_ID | Identity |

### Special Behavior

- `auto_accept` → trusted evidence
- optional vs required → flexible compliance

---

## ✅ Checklist (Requirement Engine)

```yaml
checklist:
  - id: payload_present
```

### Architectural Role

```text
Checklist = normalization layer
```

It converts:
- payload
- documents
- signals

into:
👉 **binary requirements**

---

### Types

| Type | Meaning |
|------|--------|
| auto | system-evaluated |
| manual | requires human/system action |

---

## 🚪 Gates (Decision Layer)

```yaml
gates:
  settleable:
    conditions:
      - payload_present
      - amount_verified
```

### Architectural Role

```text
Gate = final decision function
```

### Difference from Standard Drivers

| Type | Standard | This Driver |
|------|--------|------------|
| Gate Logic | expression | condition list |

👉 This requires **normalization into boolean expressions**

---

## 🔁 Integration Layer (Form Mapping)

```yaml
form_flow_mapping:
  payload:
    patient_name: "bio_fields.name | bio_fields.full_name"
```

### Architectural Role

```text
Bridges external systems → payload
```

Sources:
- Contact
- Wallet
- User profile

---

## 🧾 Audit Layer

```yaml
audit:
  capture_all: true
  retention_days: 2555
```

### Architectural Role

```text
Observability + compliance
```

- Full traceability
- 7-year retention (healthcare requirement)

---

## ⚠️ Architectural Deviations

This driver is **NOT fully canonical**.

| Area | Canonical | philhealth-bst |
|------|----------|----------------|
| Payload | JSON Schema | Form schema |
| Gates | Expressions | Condition arrays |
| Checklist | structured | simplified |
| Documents | registry list | keyed map |

---

## 🔧 Required Normalization

Before execution, convert into standard model:

### 1. Payload → JSON Schema
### 2. Documents → Registry format
### 3. Checklist → structured template
### 4. Gates → boolean expressions

---

## 🧠 Design Interpretation

This driver represents:

> A real-world healthcare workflow encoded as policy

It optimizes for:
- usability
- human processes
- partial compliance

---

## 🧭 When to Use This Pattern

Use this style when:

- workflows are human-heavy
- requirements are evolving
- UI-driven forms are needed

Avoid when:
- strict automation required
- financial rules must be deterministic

---

## 🧠 Final Guidance

When working with this driver:

Ask:

> “Is this enforcing policy… or describing a workflow?”

If it describes workflow → normalize it  
If it enforces policy → evaluate it

---

End of Document.
