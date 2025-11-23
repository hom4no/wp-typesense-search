# Jak Typesense určuje relevance výsledků

## Základní principy

Typesense používá několik faktorů pro určení relevance výsledků:

### 1. **Pořadí polí v `query_by`**
Pole uvedená dříve mají automaticky větší váhu. Například:
- `query_by: 'name,description'` - `name` má větší váhu než `description`

### 2. **Typ shody (Match Type)**
Typesense rozlišuje tři typy shody v pořadí priority:
1. **Exact match** - přesná shoda (nejvyšší priorita)
2. **Prefix match** - shoda na začátku slova
3. **Fuzzy match** - přibližná shoda s tolerancí překlepů

### 3. **Váhy polí (`query_by_weights`)**
Můžete explicitně nastavit váhy pro jednotlivá pole:
```
query_by_weights: 'name:3,description:1,sku:2'
```
To znamená, že `name` má 3x větší váhu než `description`, a `sku` má 2x větší váhu.

### 4. **Prioritizace přesné shody (`prioritize_exact_match`)**
Když je `true`, výsledky s přesnou shodou jsou vždy na prvních pozicích.

### 5. **Prioritizace pozice tokenu (`prioritize_token_position`)**
Když je `true`, výsledky, kde se hledaný term vyskytuje dříve v textu, mají vyšší prioritu.

### 6. **Tolerance překlepů (`typo_tolerance`)**
- `off` - žádná tolerance
- `min` - minimální tolerance
- `default` - výchozí tolerance
- `max` - maximální tolerance

### 7. **Počet překlepů (`num_typos`)**
Explicitní počet povolených překlepů (0-2).

## Aktuální nastavení v pluginu

### Produkty
```php
query_by: 'name,description,short_description,sku'
query_by_weights: 'name:3,description:1,short_description:1,sku:2'
prioritize_exact_match: true
prioritize_token_position: true
```

To znamená:
- Název produktu má největší váhu (3x)
- SKU má střední váhu (2x)
- Popis a krátký popis mají základní váhu (1x)
- Přesné shody jsou upřednostněny
- Pozice tokenu v textu ovlivňuje ranking

## Jak upravit relevance

### 1. Změnit váhy polí
V `class-typesense-search-frontend.php` upravte `query_by_weights`:
```php
'query_by_weights' => 'name:5,description:1,sku:3'
```

### 2. Změnit pořadí polí
Upravte `query_by` - pole uvedená dříve mají větší váhu:
```php
'query_by' => 'sku,name,description'  // SKU má nyní největší váhu
```

### 3. Vypnout prioritizaci přesné shody
```php
'prioritize_exact_match' => false
```

### 4. Upravit toleranci překlepů
```php
'typo_tolerance' => 'max',  // nebo 'min', 'off'
'num_typos' => 2  // explicitní počet překlepů
```

## Příklady

### Příklad 1: Upřednostnit SKU
```php
'query_by' => 'sku,name,description',
'query_by_weights' => 'sku:5,name:3,description:1'
```

### Příklad 2: Přísnější vyhledávání (bez tolerance překlepů)
```php
'typo_tolerance' => 'off',
'num_typos' => 0
```

### Příklad 3: Volnější vyhledávání (maximální tolerance)
```php
'typo_tolerance' => 'max',
'num_typos' => 2
```

## Další užitečné parametry

- `sort_by` - řazení výsledků (např. `price:asc`, `name:desc`)
- `facet_by` - faceting pro filtrování
- `filter_by` - filtrování výsledků
- `exclude_fields` - vyloučit pole z výsledků
- `include_fields` - zahrnout pouze určitá pole

