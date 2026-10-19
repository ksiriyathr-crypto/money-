# US Stock Portfolio Analyzer (Local MVP)

## Requirements
- PHP 8.0+
- MySQL 5.7+/MariaDB 10.4+
- Apache (XAMPP, AppServ, or similar)

## Install
1. Copy `us_stock_portfolio_analyzer` into your web root (e.g. `C:\AppServ\www\`).
2. Create a MySQL database by importing `database.sql` in phpMyAdmin.
3. Edit `config.php` with your local database username/password.
4. Open `http://localhost/us_stock_portfolio_analyzer/`.
5. Add a ticker (e.g. AAPL), quantity, average cost, and current price. The app calculates market value and unrealized P/L.

## What this MVP does
- Local portfolio overview
- Add/edit/delete holdings
- Average cost, market value, unrealized P/L and return %
- Separate short-term and long-term strategy notes
- Rule-based technical watch signals using manually entered price, EMA20, EMA50, RSI14, support, resistance
- Risk/reward calculator for an entry, stop and target
- No broker credentials are stored or requested

## Important limitations
- Prices and indicators are entered manually in this first version. It does NOT fetch live quotes or connect to a broker.
- Direct broker connections depend on the broker, account permissions, API availability and region. Never paste broker passwords/API secrets into this app. A later integration should use the broker's official API and read-only permissions.
- Signals are rule-based screening aids, not guaranteed predictions or personalized financial advice. Validate data and use paper trading before risking money.
