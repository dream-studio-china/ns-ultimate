# Independent applications

This directory is reserved for self-contained projects that are independent of the main `business/` modules and the composed admin/API application. Add an application here only when it has a distinct runtime or deployment boundary; keep its source, dependencies, configuration, and operating instructions with that application.

This is currently a placeholder. No existing integration, build, test, CI, or deployment workflow discovers, starts, or deploys applications under `apps/`. The main Docker build context explicitly excludes this directory. Build and operate any future app through its own documented workflow, or add deliberate project-level wiring. Product capabilities that extend the main application remain under `business/` and are composed through `integration/`.
