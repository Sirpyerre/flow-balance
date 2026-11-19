# Validación de Corrección de cobros

- Phinx aplicó `20261119000100` en PostgreSQL de pruebas y en la base local de desarrollo. En un esquema aislado se comprobaron la conservación de filas anteriores, el ciclo de reversión y reaplicación, las restricciones del historial y el bloqueo de reversión cuando hay revisiones.
- PHPUnit completó 22 pruebas con 328 aserciones. Se verificaron autorización y CSRF, compatibilidad del PATCH mínimo, cambio de mes, saldo, motivos opcionales, historial único, reintentos y exclusión de cobros eliminados. Con dos conexiones se verificó que editar o agregar un cargo espera el bloqueo de la corrección. La versión impide confirmar una baja si cambió el cobro o se agregó un cargo después de abrirlo.
- Astro compiló nueve páginas y Playwright completó siete pruebas en Chrome sobre HTTPS, incluida la edición y eliminación a 360 px, la cancelación, los conflictos de versión y la pérdida de ambas respuestas tras una escritura confirmada. La regresión de la PWA comprobó que las rutas privadas quedan fuera de caché.
- No se ejecutó una validación adicional en Android físico.

## Resumen de pagos del proyecto

- El usuario confirmó el rediseño del formulario de registro, los flujos de edición y eliminación de cobros y el estado de pagos del proyecto.

- PHPUnit pasó 22 pruebas con 334 aserciones en PostgreSQL de pruebas. El detalle cuenta cobros activos históricos en otras monedas, excluye cargos separados y bajas y conserva los importes en MXN. La lectura de otra cuenta devuelve el mismo 404 que un proyecto inexistente.
- Astro compiló nueve páginas y Playwright pasó nueve pruebas sobre HTTPS. Se verificaron el alta y la edición a 360 px y en escritorio, los textos de cantidad faltante, alcanzada, adicional y sin acuerdo, la recuperación tras perder la respuesta de guardado y el descarte de una consulta tardía. La suite incluyó la regresión de privacidad sin conexión de la PWA.
