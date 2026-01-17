# Simple Shopify Redirects

## 🎯 Was macht das Plugin?

Dieses WordPress-Plugin hilft dir, schnell und einfach 301-Redirects für deine Shopify-Migration zu erstellen.

## ✨ Features

- ✅ **Super einfach**: Nur 3 Schritte
- ✅ **URLs hochladen**: Text einfügen ODER Datei hochladen
- ✅ **Automatisches Matching**: Findet passende neue URLs
- ✅ **Shopify-CSV Export**: Direkt in Shopify importierbar
- ✅ **Subsitemap-Support**: Parst automatisch alle Sub-Sitemaps

## 📦 Installation

1. ZIP-Datei in WordPress hochladen
2. Plugin aktivieren
3. Fertig!

## 🚀 Nutzung (3 einfache Schritte)

### Schritt 1: Alte URLs hinzufügen

**Variante A - Text einfügen:**
```
Redirects → Tab "Text einfügen"
URLs einfügen (eine pro Zeile)
Button "URLs hinzufügen" klicken
```

**Variante B - Datei hochladen:**
```
Redirects → Tab "Datei hochladen"
Deine .txt oder .csv Datei wählen
Button "Datei hochladen" klicken
```

### Schritt 2: Matching starten

```
Sitemap-URL eingeben: https://neuer-shop.myshopify.com/sitemap.xml
Button "Jetzt matchen" klicken
Warten (kann 1-3 Minuten dauern)
```

### Schritt 3: CSV exportieren

```
Button "Shopify CSV herunterladen" klicken
CSV-Datei wird heruntergeladen
In Shopify importieren:
  → Admin → Online Store → Navigation → URL Redirects → Import
```

## 📄 Dateiformat

**Einfach nur URLs, eine pro Zeile:**
```
https://alte-domain.com/products/produkt-1
https://alte-domain.com/collections/kategorie-1
https://alte-domain.com/pages/seite-1
```

**Das Plugin erkennt automatisch:**
- Produkte (`/products/...`)
- Collections (`/collections/...`)
- Pages (`/pages/...`)
- Blogs & Articles
- Multi-Locale URLs (`/de/`, `/fr/`, etc.)

## 🎯 Matching-Algorithmus

Das Plugin matched anhand von:
- **Type** (Product → Product, Collection → Collection)
- **Handle-Similarity** (URL-Pfad Ähnlichkeit)
- **Locale** (Sprache bleibt gleich)

**Score:**
- 🟢 80-100: Sehr sicher
- 🟠 60-79: Mittelsicher
- 🔴 <60: Nicht gematched

## 💡 Tipps

1. **Große Datenmengen**: Bei >500 URLs kann Matching 2-5 Minuten dauern
2. **Sitemap**: Die Sitemap deines NEUEN Shops verwenden
3. **Subsitemaps**: Plugin parst automatisch alle Sub-Sitemaps (products_1.xml, collections_1.xml, etc.)
4. **Prüfen**: Vor Shopify-Import kurz die Vorschau checken

## 🔧 Technische Details

- **Subsitemap-Support**: Ja, automatisch
- **CSV-Format**: Shopify-kompatibel mit UTF-8 BOM
- **Pfad-Format**: `/path/to/page` (wie Shopify erwartet)
- **Performance**: Verarbeitet 500 URLs in ~2 Minuten

## 📞 Support

Bei Problemen:
- Alle URLs löschen und neu starten
- Prüfe ob Sitemap-URL korrekt ist
- Schaue in Vorschau-Tabelle ob Matches gut sind

---

**Entwickelt von:** Thilo Huellmann  
**Website:** webdesign-praxis.de  
**Version:** 1.0.0
