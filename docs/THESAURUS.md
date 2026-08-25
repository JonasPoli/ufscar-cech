# Gestão de Tesauros e Vocabulários Controlados — CECH / UFSCar

Este documento descreve o funcionamento do sistema de tesauros controlados, normalização de cadeias de texto, formatos de intercâmbio de dados e o mecanismo de fusão (*merge*) de entidades.

---

## 🎯 Objetivo dos Tesauros

Em bases bibliométricas e curriculares (como a Plataforma Lattes), os nomes de **instituições**, **países** e **autores** são frequentemente inseridos pelos próprios usuários de forma livre, gerando centenas de grafias variantes para uma mesma entidade:

- **Exemplo de Instituição**: `Universidade Federal de São Carlos`, `UFSCar`, `Univ. Fed. de São Carlos`, `U.F.S.CARLOS`, `Federal University of Sao Carlos`.
- **Exemplo de País**: `Brasil`, `Brazil`, `Republica Federativa do Brasil`, `BRA`.
- **Exemplo de Autor**: `Silva, Carlos Alberto`, `SILVA, C. A.`, `SILVA, Carlos A.`, `SILVA, CARLOS ALBERTO DA`.

O subsistema de tesauros padroniza essas variações sob um **Termo Preferido (Preferred / Canonical Name)**, mantendo uma lista vinculada de **Variantes (Variants / Synonyms)**.

---

## ⚙️ Algoritmo de Normalização de Strings (`StringNormalizer`)

A classe `App\Service\Thesaurus\StringNormalizer` fornece métodos para padronização determinística:

```php
// Remove acentuação, caracteres especiais e converte para caixa alta
$normalized = StringNormalizer::normalizeString("Universidade Federal de São Carlos");
// Resultado: "UNIVERSIDADE FEDERAL DE SAO CARLOS"

// Geração de slugs para URLs amigáveis
$slug = StringNormalizer::slugify("João da Silva Santos");
// Resultado: "joao-da-silva-santos"
```

---

## 📄 Formatos de Intercâmbio Suportados

O serviço `ThesaurusFileService` permite importar e exportar tesauros nos seguintes formatos:

### 1. Formato VantagePoint (`.the`)
Formato padrão amplamente utilizado no software de inteligência bibliométrica VantagePoint:

```text
**#brasil
100 1 ^brasil$
100 1 ^brazil$
100 1 ^republica federativa do brasil$
100 1 ^bra$

**#estados unidos
100 1 ^estados unidos da america$
100 1 ^united states$
100 1 ^usa$
```

- Cada bloco começa com `**#nome_preferido`.
- Cada variante é representada na linha `100 1 ^variante$`.

---

### 2. Formato CSV (`.csv`)
Tabela simples delimitada por ponto e vírgula com suporte a variantes agrupadas por barra vertical `|`:

```csv
preferred_name;variant_name
Brasil;Brazil|Republica Federativa do Brasil|BRA
Estados Unidos;United States|USA|EUA
Universidade Federal de São Carlos;UFSCar|Univ Fed Sao Carlos
```

---

### 3. Formato JSON (`.json`)
Estrutura em árvore JSON de alta interoperabilidade:

```json
[
  {
    "header": "Universidade Federal de São Carlos",
    "variations": [
      "UFSCar",
      "Univ Fed Sao Carlos",
      "Federal University of Sao Carlos"
    ]
  },
  {
    "header": "Brasil",
    "variations": [
      "Brazil",
      "BRA"
    ]
  }
]
```

---

### 4. Formato XML (`.xml`)
Estrutura hierárquica XML para intercâmbio de tesauros:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<thesaurus>
  <term>
    <header>Universidade Federal de São Carlos</header>
    <variations>
      <variation>UFSCar</variation>
      <variation>Univ Fed Sao Carlos</variation>
    </variations>
  </term>
</thesaurus>
```

---

## 🔀 Mecanismo de Fusão de Entidades (`EntityMergeService`)

Quando o administrador identifica registros duplicados na base (por exemplo, dois registros para a mesma universidade cadastrados com nomes ligeiramente diferentes), o sistema executa uma **fusão atômica** via `EntityMergeService`:

1. O usuário seleciona 2 ou mais registros na tabela e clica em **"Mesclar Selecionados"**.
2. Uma caixa de diálogo modal HTML (`<sl-dialog>`) solicita a escolha de qual será o **Registro Principal (Master)**.
3. O serviço `EntityMergeService`:
   - Adiciona os nomes dos registros secundários como **novas variantes** do registro principal (evitando perdas de sinônimos).
   - Transfere todas as variações existentes dos registros secundários para o registro principal.
   - Remove duplicatas de variantes idênticas.
   - Deleta os registros secundários do banco de dados com segurança transacional.
