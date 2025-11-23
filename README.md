# Typesense Search pro WooCommerce

Rychlé a moderní vyhledávání pro WooCommerce e-shopy postavené na technologii Typesense.

## Funkce

- **Okamžité vyhledávání:** Výsledky se zobrazují v reálném čase při psaní.
- **Tolerancí překlepů:** Najde produkty i při chybách v zadání.
- **Řazení:** Podpora řazení podle relevance, dostupnosti (skladem první) a dalších parametrů.
- **Integrace do Bricks Builderu:** Obsahuje vlastní Query Loop provider pro snadné zobrazení výsledků v Bricks šablonách.
- **Automatická indexace:** Produkty se automaticky indexují při uložení/aktualizaci.
- **Automatické aktualizace:** Plugin se umí sám aktualizovat přímo z GitHubu.

## Požadavky

- WordPress 5.8+
- WooCommerce
- PHP 7.4+
- Běžící server Typesense (nebo cloud instance)

## Instalace

1. Stáhněte si nejnovější verzi pluginu ze sekce [Releases](https://github.com/hom4no/typesense-search/releases).
2. Nahrajte plugin do WordPressu (Pluginy -> Přidat nový -> Nahrát plugin).
3. Aktivujte plugin.
4. V administraci přejděte do nastavení Typesense Search a zadejte údaje k vašemu Typesense serveru (API Key, Host, Port).
5. Klikněte na "Indexovat vše" pro prvotní naplnění dat.

## Použití v Bricks Builderu

Pro zobrazení výsledků vyhledávání v Bricks:
1. Vložte element **Container**.
2. Zapněte **Use Query Loop**.
3. V nastavení Query vyberte Type: **Typesense Search Results**.
4. Uvnitř kontejneru si nastylovyjte vzhled produktu (nadpis, obrázek, cena...) pomocí standardních dynamických dat `{post_title}`, `{featured_image}` atd.

## Autor

Ondřej Homan

