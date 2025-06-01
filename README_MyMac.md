# Context Generator for LLM with MCP Server

[![Docs](https://img.shields.io/badge/docs-green)](https://docs.ctxgithub.com/)
[![Json schema](https://img.shields.io/badge/json_schema-blue)](https://raw.githubusercontent.com/context-hub/generator/refs/heads/main/json-schema.json)
[![Telegram](https://img.shields.io/badge/telegram-blue.svg?logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZD0iTTEyIDI0YzYuNjI3IDAgMTItNS4zNzMgMTItMTJTMTguNjI3IDAgMTIgMCAwIDUuMzczIDAgMTJzNS4zNzMgMTIgMTIgMTJaIiBmaWxsPSJ1cmwoI2EpIi8+PHBhdGggZmlsbC1ydWxlPSJldmVub2RkIiBjbGlwLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik01LjQyNSAxMS44NzFhNzk2LjQxNCA3OTYuNDE0IDAgMCAxIDYuOTk0LTMuMDE4YzMuMzI4LTEuMzg4IDQuMDI3LTEuNjI4IDQuNDc3LTEuNjM4LjEgMCAuMzIuMDIuNDcuMTQuMTIuMS4xNS4yMy4xNy4zMy4wMi4xLjA0LjMxLjAyLjQ3LS4xOCAxLjg5OC0uOTYgNi41MDQtMS4zNiA4LjYyMi0uMTcuOS0uNSAxLjE5OS0uODE5IDEuMjI5LS43LjA2LTEuMjI5LS40Ni0xLjg5OC0uOS0xLjA2LS42ODktMS42NDktMS4xMTktMi42NzgtMS43OTgtMS4xOS0uNzgtLjQyLTEuMjA5LjI2LTEuOTA4LjE4LS4xOCAzLjI0Ny0yLjk3OCAzLjMwNy0zLjIyOC4wMS0uMDMuMDEtLjE1LS4wNi0uMjEtLjA3LS4wNi0uMTctLjA0LS4yNS0uMDItLjExLjAyLTEuNzg4IDEuMTQtNS4wNTYgMy4zNDgtLjQ4LjMzLS45MDkuNDktMS4yOTkuNDgtLjQzLS4wMS0xLjI0OC0uMjQtMS44NjgtLjQ0LS43NS0uMjQtMS4zNDktLjM3LTEuMjk5LS43OS4wMy0uMjIuMzMtLjQ0Ljg5LS42NjlaIiBmaWxsPSIjZmZmIi8+PGRlZnM+PGxpbmVhckdyYWRpZW50IGlkPSJhIiB4MT0iMTEuOTkiIHkxPSIwIiB4Mj0iMTEuOTkiIHkyPSIyMy44MSIgZ3JhZGllbnRVbml0cz0idXNlclNwYWNlT25Vc2UiPjxzdG9wIHN0b3AtY29sb3I9IiMyQUFCRUUiLz48c3RvcCBvZmZzZXQ9IjEiIHN0b3AtY29sb3I9IiMyMjlFRDkiLz48L2xpbmVhckdyYWRpZW50PjwvZGVmcz48L3N2Zz4K)](https://t.me/spiralphp/2504)
[![License](https://img.shields.io/packagist/l/context-hub/generator)](https://packagist.org/packages/context-hub/generator)
[![Latest Version](https://img.shields.io/packagist/v/context-hub/generator)](https://packagist.org/packages/context-hub/generator)

## Part I: What is Context Generator?

### Introduction

Context Generator is a tool designed to solve a common problem when working with LLMs like ChatGPT and Claude: **providing sufficient context about your codebase.**

> There is an article about Context Generator on [Medium](https://medium.com/@butschster/context-not-prompts-2-0-the-evolution-9c4a84214784) that explains the motivation behind the project and the problem it solves.

It automates the process of building context files from various sources:
- Code files
- GitHub repositories
- Git commit changes and diffs
- Web pages (URLs) with CSS selectors
- Plain text

### Why You Need This

When working with AI-powered development tools, context is everything:

- **Code Refactoring Assistance**: Want AI help refactoring a complex class? Context Generator builds a properly formatted document containing all relevant code files.

- **Multiple Iteration Development**: Working through several iterations with an AI helper requires constantly updating the context. Context Generator automates this process.

- **Documentation Generation**: Transform your codebase into comprehensive documentation by combining source code with custom explanations. Use AI to generate user guides, API references, or developer documentation based on your actual code.

- **Seamless AI Integration**: With MCP support, [connect](https://docs.ctxgithub.com/mcp-server.html) Claude AI directly to your codebase, allowing for real-time, context-aware assistance without manual context sharing.

### How It Works

1. Gathers code from files, directories, GitHub repositories, web pages, or custom text
2. Targets specific files through pattern matching, content search, size, or date filters
3. Applies optional modifiers (like extracting PHP signatures without implementation details)
4. Organizes content into well-structured markdown documents
5. Saves context files ready to be shared with LLMs
6. Optionally serves context through an MCP server, allowing AI assistants like Claude to directly access project information

## Part II: Getting Started with Context Generator

### Installation

Download and install the tool using the installation script:

```bash
curl -sSL https://raw.githubusercontent.com/context-hub/generator/main/download-latest.sh | sh
```

This installs the `ctx` command to your system (typically in `/usr/local/bin`).

> **Want more options?** See the complete [Installation Guide](https://docs.ctxgithub.com/getting-started.html) for alternative installation methods.

### Basic Configuration

1. **Initialize a Configuration File**:
   Create a new configuration file in your project directory:
   ```bash
   ./ctx-ee init
   ```
   This generates a `context.yaml` file with a basic structure to get you started.

   > **Pro tip:** Run `ctx init --type=json` if you prefer JSON configuration format.
   > Check the [Command Reference](https://docs.ctxgithub.com/getting-started/command-reference.html) for all available commands and options.

2. **Describe Your Project Structure**:
   Edit the generated `context.yaml` file to specify what code or content you want to include. For example:

   ```yaml
   documents:
     - description: "User Authentication System"
       outputPath: "auth-context.md"
       sources:
         - type: file
           description: "Authentication Controllers"
           sourcePaths:
             - src/Auth
           filePattern: "*.php"

         - type: file
           description: "Authentication Models"
           sourcePaths:
             - src/Models
           filePattern: "*User*.php"
   ```

   This configuration will gather all PHP files from the `src/Auth` directory and any PHP files containing "User" in their name from the `src/Models` directory.

3. **Build the Context**:
   Generate your context file by running:
   ```bash
   ./ctx-ee
   ```
   The tool will process your configuration and create the specified output file (`auth-context.md` in our example).

   > **Tip**: Configure [Logging](https://docs.ctxgithub.com/advanced/logging.html) with `-v`, `-vv`, or `-vvv` for detailed output

### Using with LLMs

Upload or paste the generated context file to your favorite LLM (like ChatGPT or Claude). Now you can ask specific questions about your codebase, and the LLM will have the necessary context to provide accurate assistance.

Example prompt:

> I've shared my authentication system code with you. Can you help me identify potential security vulnerabilities in the user registration process?

> **Next steps:** Check out [Development with Context Generator](https://docs.ctxgithub.com/advanced/development-process.html) for best practices on integrating context generation into your AI-powered development workflow.

### Advanced Configuration Options

- Learn about [Document Structure](https://docs.ctxgithub.com/documents.html) and properties
- Explore different source types like [GitHub](https://docs.ctxgithub.com/sources/github-source.html), [Git Diff](https://docs.ctxgithub.com/sources/git-diff-source.html), or [URL](https://docs.ctxgithub.com/sources/url-source.html)
- Apply [Modifiers](https://docs.ctxgithub.com/modifiers.html) to transform your content (like extracting PHP signatures)
- Discover how to use [Environment Variables](https://docs.ctxgithub.com/environment-variables.html) in your config
- Use [IDE Integration](https://docs.ctxgithub.com/getting-started/ide-integration.html) for autocompletion and validation

## Part III: Advanced Features

### JSON Schema

For better editing experience, Context Generator provides a JSON schema for autocompletion and validation in your IDE:

```bash
# Show schema URL
./ctx-ee schema

# Download schema to current directory
./ctx-ee schema --download
```

> **Learn more:** See [IDE Integration](https://docs.ctxgithub.com/getting-started/ide-integration.html) for detailed setup instructions for VSCode, PhpStorm, and other editors.

### MCP Server Integration

For a more seamless experience, you can connect Context Generator directly to Claude AI using the MCP server.

Point the MCP client to the Context Generator server:

```json
{
  "mcpServers": {
    "ctx": {
      "command": "ctx server -c /path/to/your/project"
    }
  }
}
```

> **Note:** Read more about [MCP Server](https://docs.ctxgithub.com/mcp-server.html) for detailed setup instructions.

Now you can ask Claude questions about your codebase without manually uploading context files!

## Part IV: Development and Custom Builds

### Git Repository Setup

This section contains instructions for setting up and managing your Git repository when working with the Context Generator.

### Building and Using the macOS Executable

#### Adding Your Private Repository Based on Remote

1. Check your current remote configuration:
   ```bash
   git remote -v
   ```

2. Add your GitHub repository as a second remote:
   ```bash
   git remote add origin git@github.com-IgorPalinchakHub:IgorPalinchakHub/ctx-mcp-fork.git
   ```

3. Pull from the original remote and push to your own:
   ```bash
   git pull https://github.com/context-hub/generator.git main
   ```

4. Set your repository as the default upstream:
   ```bash
   git branch --set-upstream-to=origin/local-dev-setup
   ```

5. Configure user information for this repository:
   ```bash
   git config user.name "IhorPalinchakN"
   git config user.email "palinchakihor.dev@gmail.com"
   ```

6. Update remote URL and push to your repository:
   ```bash
   git remote set-url origin git@github.com-IgorPalinchakHub:IgorPalinchakHub/ctx-mcp-fork.git
   git push origin local-dev-setup
   ```

#### Building the Executive macOS File

To build the executive macOS file for your own use:

1. Push your changes to your fork repository:
   ```bash
   git add .
   git commit -m "Update Context Generator for macOS build"
   git push origin local-dev-setup
   ```

2. Create a new tag on GitHub to trigger the build process:
   ```bash
   git tag v1.0.1
   git push origin v1.0.1
   ```

3. Wait for GitHub Actions to complete building the file.

4. Download the built file to your project:
   ```bash
   ./Mydownload-latest.sh
   ```
   This will download the file to the `.output` directory in your project.

5. Copy the executable to your project directory:
   ```bash
   cp .output/ctx-ee /path/to/your/project/
   ```

#### Using the Executable in Your Projects

When using the executable in your projects:
0. **Make it executable**:
   ```bash
     chmod +x ./ctx-ee
    ```

1. **Initialize a Configuration File**:
   Create a new configuration file in your project directory:
   ```bash
   ./ctx-ee init
   ```
   This will generate a `context.yaml` file configured for the executable version.

2. **Update the Schema**:
   Make sure to update the JSON schema in your project:
   ```bash
   ./ctx-ee schema --download
   ```
   This will download the latest schema file to your project directory for IDE integration and validation.

## Additional Resources

### Documentation

For complete documentation, including all available features and configuration options, please visit:
https://docs.ctxgithub.com

### License

This project is licensed under the MIT License.
