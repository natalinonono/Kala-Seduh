<?php
/**
 * Partial HTML <head> bersama untuk seluruh halaman Kala Seduh Slow Bar.
 * Menghubungkan Google Fonts (EB Garamond & Manrope), Material Symbols,
 * Tailwind CSS CDN + palet tema Slow Bar (Warm Cream, Deep Espresso, Terracotta).
 */

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Kala Seduh — Slow Bar & Manual Brew';
$isPrintPage = $isPrintPage ?? false;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($pageTitle) ?></title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400..700;1,400..700&family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,300..600,0..1,-50..200" rel="stylesheet" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            cream: '#F5F0E6',
            paper: '#FAF8F5',
            surface: '#FEF9EF',
            'surface-low': '#F8F3E9',
            'surface-mid': '#F2EDE3',
            'surface-high': '#EDE8DE',
            espresso: '#2C1E16',
            'espresso-dark': '#130803',
            terracotta: '#C86D51',
            'terracotta-dark': '#A8563C',
            'terracotta-soft': '#FAECE6',
            sage: '#6B705C',
            'sage-soft': '#E8F0EC',
            'sage-text': '#52796F',
            sand: '#E3DDD2',
            muted: '#4E4540',
            outline: '#80756F',
          },
          fontFamily: {
            serif: ['"EB Garamond"', 'Georgia', 'serif'],
            sans: ['Manrope', 'system-ui', 'sans-serif'],
          },
          boxShadow: {
            ambient: '0 12px 32px -8px rgba(44, 30, 22, 0.08), 0 4px 12px -2px rgba(44, 30, 22, 0.04)',
          },
        },
      },
    };
  </script>
  <style>
    @layer base {
      html {
        scroll-behavior: smooth;
      }
      body {
        background-color: #F5F0E6;
        color: #2C1E16;
        font-family: 'Manrope', system-ui, sans-serif;
      }
    }
    .font-editorial {
      font-family: 'EB Garamond', Georgia, serif;
    }
    .tabular-nums {
      font-variant-numeric: tabular-nums;
    }
  </style>
  <?php if ($isPrintPage): ?>
  <link rel="stylesheet" href="/css/print.css" />
  <?php endif; ?>
</head>
