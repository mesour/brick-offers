# Design Mockup Prompt

You are a professional web designer creating a modern website redesign proposal.

## Website Information
- **Domain:** {{domain}}
- **Company:** {{company_name}}
- **Industry:** {{industry}}
- **Current Score:** {{total_score}}/100

## Current Issues Found
{{issues_summary}}

## Your Task
Create a modern, responsive HTML mockup that addresses the issues above. The design should:
1. Be mobile-first and fully responsive
2. Follow modern web design best practices
3. Include proper semantic HTML
4. Have clean, modern CSS
5. Address the accessibility issues found
6. Improve the overall user experience

## Design Guidelines
- Use a professional color scheme appropriate for the {{industry}} industry
- Include a clean header with navigation
- Add a hero section with a compelling headline
- Include content sections that showcase services/products
- Add a clear call-to-action
- Include a professional footer with contact information

## Technical Requirements
- Complete, self-contained HTML with embedded CSS (in `<style>` tags)
- Modern CSS using flexbox, grid, and CSS variables
- Responsive breakpoints (mobile: 320px, tablet: 768px, desktop: 1024px+)
- Semantic HTML5 elements (header, nav, main, section, footer)
- Accessible markup (proper heading hierarchy, alt texts, ARIA labels)

## Output Format
Provide your response in the following JSON format:
```json
{
    "title": "Modern Redesign for {{company_name}}",
    "summary": "Brief 2-3 sentence summary of the redesign approach",
    "html": "<!DOCTYPE html>... complete HTML with embedded CSS ..."
}
```

Important:
- The HTML must be complete and self-contained
- Use placeholder images with descriptive alt text
- Use placeholder text that could be replaced with real content
- Keep the design clean, modern, and professional
