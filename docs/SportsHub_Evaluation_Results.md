### 5.3 System Evaluation

The evaluation of the SportsHub was conducted among fifteen (15) respondents from Surigao del Norte National High School: the school's designated Sports Coordinator, three (3) faculty members serving as sports advisers, and eleven (11) student-athletes from different sports categories. Each respondent interacted with the major modules of the finalized system before answering the fifteen-item questionnaire based on the ISO/IEC 25010 Software Quality Model. The results of the evaluation were used to determine whether the SportsHub met the quality standards required for practical deployment in a school setting.

#### 5.3.1 Overall Evaluation Result

Table 3 presents the weighted mean of each ISO 25010 quality characteristic and the overall weighted mean across all fifteen (15) items, together with their corresponding verbal interpretations.

| Evaluation Criterion | Number of Items | Weighted Mean | Verbal Interpretation | Quality Level |
|---|:---:|:---:|---|---|
| Usability | 5 | 4.56 | Strongly Agree | Excellent |
| Reliability | 5 | 4.45 | Agree | Very Satisfactory |
| Performance Efficiency | 5 | 4.37 | Agree | Very Satisfactory |
| **Overall** | **15** | **4.46** | **Agree** | **Very Satisfactory** |

**Table 3. Overall Weighted Mean of the SportsHub Evaluation (N = 15)**

The overall weighted mean of **4.46** falls within the 3.50 – 4.49 range, which is interpreted as **Agree** with a quality level of **Very Satisfactory**. This result indicates that the SportsHub met the quality standards required for practical deployment in a school setting. All three quality characteristics scored above 4.00, and no criterion fell below the Agree interpretation.

#### 5.3.2 Usability

The usability of the system was evaluated through five items. Table 4 shows the frequency distribution and weighted mean for each item.

| Item | Statement | 5 | 4 | 3 | 2 | 1 | Weighted Mean | Interpretation |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|---|
| A1 | Simple and easy-to-understand interface | 10 | 4 | 1 | 0 | 0 | 4.60 | Strongly Agree |
| A2 | Quick learning without extensive assistance | 8 | 6 | 1 | 0 | 0 | 4.47 | Agree |
| A3 | Easy navigation and access to features | 11 | 3 | 1 | 0 | 0 | 4.67 | Strongly Agree |
| A4 | Clear, readable, and organized layout | 9 | 5 | 1 | 0 | 0 | 4.53 | Strongly Agree |
| A5 | Clear messages, instructions, and feedback | 9 | 5 | 1 | 0 | 0 | 4.53 | Strongly Agree |
| | **Section Mean** | | | | | | **4.56** | **Strongly Agree / Excellent** |

**Table 4. Weighted Mean of Usability Items (N = 15)**

Usability obtained the highest section mean of **4.56**, which falls in the 4.50 – 5.00 range and is interpreted as **Strongly Agree** with a quality level of **Excellent**. The highest-rated item was A3 (*easy navigation and access to features*) with a weighted mean of 4.67, followed by A1 (*simple and easy-to-understand interface*) with 4.60. These results are consistent with the design decision to apply a consistent sidebar navigation, role-based redirection, and a single login screen for both administrators and athletes, which allowed respondents to move between modules without assistance. During the testing session, direct observation confirmed that respondents located the attendance, achievements, and reports modules with minimal prompting. The only usability item that did not reach the Strongly Agree range was A2 (4.47), which a few respondents attributed to the mandatory first-login password change screen; although intended as a security measure, some student-athletes initially perceived it as an unexpected step before reaching their dashboard.

#### 5.3.3 Reliability

Reliability was evaluated through five items measuring consistent operation, data integrity, fault handling, and recovery. Table 5 presents the results.

| Item | Statement | 5 | 4 | 3 | 2 | 1 | Weighted Mean | Interpretation |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|---|
| B1 | Correct and error-free performance | 9 | 5 | 1 | 0 | 0 | 4.53 | Strongly Agree |
| B2 | Accurate record saving with no data loss | 10 | 4 | 1 | 0 | 0 | 4.60 | Strongly Agree |
| B3 | Graceful handling of invalid inputs | 7 | 7 | 1 | 0 | 0 | 4.40 | Agree |
| B4 | Recovery from interruptions without data corruption | 6 | 7 | 1 | 1 | 0 | 4.20 | Agree |
| B5 | Accurate reports, rates, and certificates | 9 | 5 | 1 | 0 | 0 | 4.53 | Strongly Agree |
| | **Section Mean** | | | | | | **4.45** | **Agree / Very Satisfactory** |

**Table 5. Weighted Mean of Reliability Items (N = 15)**

Reliability obtained a section mean of **4.45**, interpreted as **Agree** with a quality level of **Very Satisfactory**. The highest-rated item was B2 (*accurate record saving with no data loss*) with a weighted mean of 4.60, indicating confidence that applications, attendance records, and achievements were persisted correctly. The lowest-rated item was B4 (*recovery from interruptions*) with 4.20, the only reliability item that received a Disagree response. Direct observation provided context for this rating: one respondent refreshed the browser in the middle of a form submission and was shown a generic error message, and another lost the connection while the offline fallback page appeared. Although no data were corrupted in either case, the generic message did not explain what had happened or what action to take. This finding aligns with the system's documented limitation that server-backed operations still require an active internet connection. The automated feature test suite, which exercises every major module, completed without failures during the evaluation period, supporting the maturity result reported under item B1.

#### 5.3.4 Performance Efficiency

Performance efficiency was evaluated through five items measuring loading speed, responsiveness, and behaviour under normal network conditions. Table 6 presents the results.

| Item | Statement | 5 | 4 | 3 | 2 | 1 | Weighted Mean | Interpretation |
|---|---|:---:|:---:|:---:|:---:|:---:|:---:|---|
| C1 | Fast loading of pages and dashboards | 8 | 6 | 1 | 0 | 0 | 4.47 | Agree |
| C2 | Prompt response to user actions | 8 | 6 | 1 | 0 | 0 | 4.47 | Agree |
| C3 | Acceptable response times under normal connection | 7 | 7 | 1 | 0 | 0 | 4.40 | Agree |
| C4 | Responsive handling of large data volumes | 6 | 6 | 2 | 1 | 0 | 4.13 | Agree |
| C5 | Efficient operation on the devices used | 7 | 7 | 1 | 0 | 0 | 4.40 | Agree |
| | **Section Mean** | | | | | | **4.37** | **Agree / Very Satisfactory** |

**Table 6. Weighted Mean of Performance Efficiency Items (N = 15)**

Performance efficiency obtained the lowest section mean among the three criteria at **4.37**, interpreted as **Agree** with a quality level of **Very Satisfactory**. Routine pages and dashboard screens were rated consistently at 4.47, and direct observation showed that most screens rendered within a few seconds on the school's network. The lowest-rated item in the entire questionnaire was C4 (*responsive handling of large data volumes*) with a weighted mean of 4.13, which received two Neutral responses and one Disagree response. Observational notes explain that the reports module and the full athlete list took noticeably longer to generate when several respondents accessed the system at the same time during peak hours. This is consistent with the study's design decision to resolve each dashboard figure through a single aggregate query, which improved everyday navigation but still leaves the CSV and PDF report generation as the heaviest operation in the system.

#### 5.3.5 Summary of Findings

The evaluation yielded the following findings:

1. The overall weighted mean of **4.46 (Agree / Very Satisfactory)** shows that the SportsHub met the quality standards required for practical deployment in a school setting.
2. **Usability** ranked highest at **4.56 (Excellent)**, confirming that the interface, navigation, and visual design were accessible to users without technical training — an important result given that the intended users include faculty sports advisers who are not expected to have technical backgrounds.
3. **Reliability (4.45)** and **Performance Efficiency (4.37)** both rated **Very Satisfactory**, indicating consistent operation and acceptable response times, but with identifiable areas for improvement.
4. The two lowest-rated items — recovery from interruptions (B4 = 4.20) and handling of large data volumes (C4 = 4.13) — correspond directly to the system's known limitations regarding offline operations and report generation.

Based on these results, the following improvements are recommended for the future development of the system: (1) provide a clearer in-application message when an action fails because the connection was lost, so users know whether their submission was saved; (2) optimize report and athlete-list generation through pagination and indexed queries so that large exports remain responsive during peak usage; and (3) add a brief first-login guide so that the mandatory password change is presented as a normal security step rather than an unexpected screen. Findings from the direct observations above supplement the quantitative data and are discussed alongside the questionnaire results to provide a comprehensive assessment of the system's performance.
