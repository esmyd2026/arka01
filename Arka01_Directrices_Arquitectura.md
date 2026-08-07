# Directrices de Arquitectura, Rendimiento y Desarrollo del Proyecto

A partir de este momento quiero que el desarrollo del proyecto siga una arquitectura profesional, escalable y orientada al rendimiento. No quiero que toda la lógica dependa exclusivamente del backend de Laravel ni tampoco trasladar indiscriminadamente toda la lógica a la base de datos. Cada decisión técnica debe tomarse evaluando cuál es la mejor capa para resolver cada problema.

---

# 1. Principios de Arquitectura

Antes de implementar cualquier funcionalidad, analiza cuál es la mejor alternativa entre:

* Backend de Laravel.
* Triggers de Base de Datos.
* Funciones o Procedimientos Almacenados.
* Vistas o Vistas Materializadas (cuando el motor lo permita).
* Jobs.
* Queue.
* Scheduler de Laravel.
* Cron Jobs.
* Caché.
* Eventos.
* Listeners.

Cada componente debe cumplir únicamente la responsabilidad para la cual fue diseñado.

No quiero mover lógica a la base de datos únicamente porque sea posible hacerlo.

Quiero que siempre prevalezcan:

* Rendimiento.
* Escalabilidad.
* Simplicidad.
* Mantenibilidad.
* Legibilidad.
* Bajo consumo de recursos.
* Facilidad de futuras modificaciones.

Si una automatización no representa una mejora real, prefiero mantenerla dentro de Laravel.

---

# 2. Criterio Técnico Antes de Implementar

Antes de implementar cualquier solución, analiza y explica brevemente:

* Problema detectado.
* Alternativas posibles.
* Solución propuesta.
* Beneficio esperado.
* Impacto en rendimiento.
* Riesgos.
* Por qué esa alternativa es mejor que las demás.

Si una mejora no aporta beneficios reales, no la implementes.

No quiero sobreingeniería.

---

# 3. Automatización mediante Base de Datos

Cuando una automatización realmente mejore el rendimiento, utiliza:

* Triggers.
* Funciones.
* Procedimientos almacenados.
* Vistas.
* Vistas materializadas (si aplica).

Cada uno deberá cumplir las siguientes reglas:

## Nombres

Todos los nombres deberán estar en español y ser totalmente descriptivos.

Ejemplos:

* trigger_actualizar_estado_conductor
* vista_clientes_activos
* fn_calcular_promedio_calificaciones
* sp_generar_estadisticas

Nunca utilizar nombres genéricos.

---

## Comentarios

Todo el código SQL deberá estar documentado.

Debe indicar:

* Qué hace.
* Cuándo se ejecuta.
* Qué tablas afecta.
* Qué campos modifica.
* Motivo de su creación.
* Beneficio esperado.
* Fecha de creación (cuando corresponda).

No quiero código sin comentarios.

---

# 4. Laravel

Todo el código deberá seguir buenas prácticas.

Los nombres de:

* Clases
* Servicios
* Métodos
* Jobs
* Eventos
* Listeners
* Policies
* Requests
* Controladores

deberán ser descriptivos y preferiblemente en español, respetando las convenciones de Laravel cuando sea necesario.

Cuando exista lógica compleja deberá estar comentada.

No quiero código difícil de entender.

---

# 5. Scheduler, Queue y Cron Jobs

Analiza continuamente qué procesos pueden ejecutarse de forma asíncrona.

Ejemplos:

* Recalcular estadísticas.
* Actualizar indicadores.
* Cambiar estados automáticamente.
* Limpiar registros temporales.
* Sincronizar información.
* Enviar correos.
* Enviar notificaciones.
* Procesar imágenes.
* Generar reportes.
* Actualizar métricas.
* Sincronizar APIs externas.

Cuando sea conveniente utiliza:

* Queue Jobs.
* Scheduler de Laravel.
* Cron Jobs.

La frecuencia dependerá de la necesidad:

* Cada minuto.
* Cada 5 minutos.
* Cada 15 minutos.
* Cada hora.
* Diariamente.
* Semanalmente.

No quiero procesos ejecutándose innecesariamente.

Cada Job debe tener una justificación técnica.

---

# 6. Optimización Continua

Durante todo el desarrollo analiza continuamente oportunidades para mejorar el rendimiento.

Cuando detectes una mejora:

Explica:

* Qué problema existe.
* Cómo lo solucionarás.
* Qué beneficio tendrá.
* Si existen riesgos.
* Qué impacto tendrá sobre el rendimiento.

Luego implementa la mejora únicamente si realmente vale la pena.

---

# 7. Registro de Usuarios

El sistema deberá permitir distintos tipos de usuarios.

Inicialmente existirán dos perfiles:

* Cliente
* Conductor

Antes de registrarse el usuario deberá escoger el tipo de cuenta que desea crear.

No deberá existir un único formulario para todos.

---

## Registro de Cliente

Solicitar únicamente la información necesaria.

Ejemplo:

* Nombres
* Apellidos
* Correo electrónico
* Teléfono
* Contraseña
* Confirmación
* Ciudad
* Ubicación (si aplica)

---

## Registro de Conductor

Además de la información básica deberá solicitar información adicional.

Ejemplo:

* Nombres
* Apellidos
* Correo
* Teléfono
* Contraseña
* Confirmación
* Número de identificación
* Licencia
* Tipo de licencia
* Fecha de vencimiento
* Fotografía del conductor
* Fotografía de licencia
* Información del vehículo
* Marca
* Modelo
* Año
* Color
* Placa
* Matrícula
* Seguro
* Documentos adicionales
* Estado de aprobación

El formulario debe ser dinámico y fácilmente extensible.

---

# 8. Validación de Usuarios Existentes

Antes de registrar cualquier usuario el sistema deberá verificar que no exista previamente.

Validar al menos:

* Correo electrónico.
* Número de teléfono.
* Número de identificación (cuando aplique).

Si ya existe:

* No permitir registros duplicados.
* Mostrar un mensaje claro.
* Permitir iniciar sesión.
* Permitir recuperar contraseña cuando corresponda.

No deben existir usuarios duplicados bajo ningún concepto.

---

# 9. Arquitectura de Usuarios

Diseña la estructura pensando en el crecimiento futuro.

En el futuro podrán existir nuevos perfiles como:

* Administrador.
* Supervisor.
* Operador.
* Empresa.
* Auditor.
* Soporte.
* Moderador.

Por ello la arquitectura debe basarse en:

* Roles.
* Permisos.
* Relaciones.
* Herencia lógica.

Evita código duplicado.

---

# 10. Base de Datos

La base de datos deberá seguir principios de normalización.

No quiero tablas duplicadas innecesariamente.

La información común deberá almacenarse en una tabla principal de usuarios.

La información específica deberá almacenarse en tablas relacionadas.

Toda la estructura debe quedar preparada para crecer sin rediseños importantes.

---

# 11. Gestión de Imágenes

Actualmente las imágenes no se están mostrando correctamente.

Debes revisar absolutamente todo el flujo.

No asumas que el problema está únicamente en el frontend.

Analiza:

* Recepción del archivo.
* Validación.
* Upload.
* Storage.
* Disco utilizado.
* Storage Link.
* Permisos.
* Ruta almacenada.
* URL generada.
* Visualización.
* Configuración de Laravel.
* Configuración del servidor.
* CORS.
* Caché.

Identifica la causa raíz y corrígela.

No implementes soluciones temporales.

---

# 12. Imágenes de Suscripción

Actualmente las imágenes cargadas durante el proceso de suscripción no aparecen en la aplicación.

Verifica:

* Que realmente se suban.
* Que se almacenen correctamente.
* Que la base de datos guarde la ruta correcta.
* Que el frontend utilice la URL adecuada.
* Que los permisos sean correctos.
* Que Laravel pueda acceder al archivo.

Corrige el problema desde su origen.

---

# 13. Fotografías de Perfil

La fotografía del usuario debe visualizarse automáticamente en todos los módulos correspondientes.

Como mínimo deberá aparecer en:

* Navbar cuando el usuario haya iniciado sesión.
* Perfil del Cliente.
* Perfil del Conductor.
* Panel Administrativo.
* Listados.
* Chats.
* Comentarios.
* Historial.
* Cualquier lugar donde se identifique al usuario.

Si el usuario no posee fotografía deberá mostrarse automáticamente un avatar por defecto.

Nunca deben aparecer imágenes rotas.

---

# 14. Optimización del Manejo de Imágenes

Implementa buenas prácticas.

* Validar formatos.
* Validar tamaño.
* Optimizar imágenes cuando sea conveniente.
* Evitar imágenes duplicadas.
* Eliminar imágenes antiguas.
* Centralizar toda la lógica en un servicio reutilizable.
* Mantener nombres consistentes.
* Evitar código repetido.

---

# 15. Calidad del Código

Todo el código deberá cumplir estándares profesionales.

* Métodos pequeños.
* Responsabilidad única.
* Código reutilizable.
* Bajo acoplamiento.
* Alta cohesión.
* Comentarios cuando la lógica lo requiera.
* Código limpio.
* Sin duplicidad.

No quiero soluciones rápidas; quiero soluciones mantenibles.

---

# 16. Buenas Prácticas Generales

Antes de desarrollar cualquier nueva funcionalidad:

* Analiza si ya existe una solución reutilizable.
* Evita duplicar código.
* Reutiliza servicios.
* Reutiliza componentes.
* Reutiliza consultas.
* Reutiliza validaciones.
* Reutiliza eventos.

Siempre piensa primero en la arquitectura y después en la implementación.

---

# 17. Rendimiento

Cada funcionalidad nueva deberá diseñarse pensando en:

* Reducir consultas innecesarias.
* Evitar N+1 Queries.
* Utilizar eager loading cuando corresponda.
* Utilizar índices en la base de datos.
* Aprovechar caché cuando aporte valor.
* Minimizar consumo de memoria.
* Reducir tiempos de respuesta.
* Mantener una experiencia fluida tanto en dispositivos móviles como en escritorio.

---

# 18. Objetivo Final

Quiero construir una aplicación con una arquitectura moderna, profesional, escalable y de alto rendimiento.

No quiero que todo dependa del backend de Laravel ni que toda la lógica se traslade a la base de datos.

Quiero que cada componente haga aquello para lo que fue diseñado:

* Laravel para la lógica de negocio.
* Base de datos para operaciones que realmente mejoren el rendimiento.
* Triggers y funciones únicamente cuando aporten valor.
* Scheduler y Jobs para procesos en segundo plano.
* Caché para optimizar consultas repetitivas.
* Servicios reutilizables para mantener un código limpio.

Cada decisión técnica deberá justificarse desde el punto de vista de la arquitectura, el rendimiento y la mantenibilidad.

**La prioridad siempre será construir un sistema rápido, limpio, escalable, mantenible y preparado para crecer, evitando la sobreingeniería y aplicando únicamente aquellas optimizaciones que generen un beneficio real.**

---

# 19. Reglas de Negocio para las Suscripciones

El sistema deberá manejar las suscripciones como una progresión de planes y **nunca como un retroceso**.

La suscripción gratuita será el punto de inicio para todos los usuarios, pero una vez que un usuario haya cambiado a un plan superior, no podrá volver a un plan inferior.

## Flujo de planes

El orden jerárquico de los planes será:

1. Gratuita
2. Básica
3. Profesional
4. Premium
5. (Los planes futuros que se agreguen deberán respetar esta jerarquía).

## Restricciones

Una vez que un usuario cambie de plan:

* No deberá visualizar nuevamente los planes inferiores.
* No deberá existir ninguna opción para regresar a un plan anterior.
* La API tampoco deberá permitir realizar un downgrade, aunque intenten consumirla manualmente.
* Todas las validaciones deberán implementarse tanto en el frontend como en el backend para garantizar la integridad de la información.

Ejemplo:

* Si el usuario está en **Gratuita**, podrá visualizar todos los planes superiores.
* Si el usuario cambia a **Básica**, ya no deberá visualizar el plan **Gratuita**.
* Si posteriormente cambia a **Profesional**, únicamente podrá visualizar **Profesional**, **Premium** y cualquier plan superior que exista en el futuro.
* Si llega a **Premium**, ya no deberá visualizar ninguno de los planes inferiores.

## Regla General

El usuario únicamente podrá:

* Mantener su plan actual.
* Actualizar a un plan superior.

Nunca podrá:

* Volver a un plan anterior.
* Comprar nuevamente un plan inferior.
* Cambiar manualmente el estado de la suscripción.

## Arquitectura

La lógica de las suscripciones no debe depender únicamente de la interfaz de usuario.

Debe validarse en:

* Base de datos (cuando corresponda).
* Backend de Laravel.
* API.
* Frontend.

Aunque un usuario modifique las peticiones desde el navegador o mediante herramientas como Postman, Insomnia o cualquier cliente HTTP, el sistema deberá impedir cualquier intento de regresar a una suscripción inferior.

## Escalabilidad

La implementación no debe basarse en comparaciones de nombres de planes.

Cada plan deberá tener un nivel o prioridad (por ejemplo, `nivel` o `orden`) que determine su jerarquía.

De esta forma, si en el futuro se agregan nuevos planes o se reorganizan las suscripciones, el sistema continuará funcionando sin necesidad de modificar la lógica de negocio.

## Experiencia de Usuario

La interfaz deberá mostrar únicamente las acciones válidas para el usuario:

* Si puede mejorar su plan, mostrar la opción de actualización.
* Si ya posee el plan más alto, informar que cuenta con la suscripción de mayor nivel disponible.
* Nunca mostrar botones, enlaces o acciones que permitan regresar a un plan inferior.

El objetivo es que la experiencia del usuario sea clara, evitando opciones inválidas y garantizando la consistencia de las reglas de negocio en toda la aplicación.
