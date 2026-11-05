import SwiftUI

extension QueueStages {
    func toQueueStage() -> QueueStage {
        switch self {
        case .wait: return .wait
        case .next: return .next
        case .ready: return .ready
        case .inProgress: return .inProgress
        case .completed: return .completed
        case .unknown: return .wait
        case .cancel: return .completed
        }
    }
}

struct Queue: View {

    @EnvironmentObject var sessionManager: SessionManager
    @EnvironmentObject var session: SessionManagerV2

    @State private var showRescheduleAlert = false
    @State private var showRefundConfirm = false
    @State private var showCashRefundQR = false
    @State private var simulatedStatus: StepStatus = .waiting
    @State private var navigateToAppointment = false
    @State private var navigateToServices = false
    @State private var navigateToChanneling = false

    private var activeActivity: Activity? {
        session.activities.first(where: {
            $0.service == session.currentService &&
            $0.isSelected &&
            $0.queueStage != .cancel
        })
    }

    private var requestedActivities: [Activity] {
        session.activities.filter {
            $0.service == session.currentService &&
            $0.queueStage == .unknown &&
            !$0.isSelected
        }
    }

    private var completedActivities: [Activity] {
        session.activities.filter {
            $0.service == session.currentService &&
            $0.queueStage == .completed
        }
    }


    private var canRecheckWithDoctor: Bool {
        guard session.currentService == .clinic else { return false }
        guard !session.hasRejoinedDoctor else { return false }

        let serviceActivities = session.activities.filter { $0.service == .clinic }
        let nonDoctorActivities = serviceActivities.filter { $0.selectedDoctor == nil }

        let hasCompletedNonDoctor = nonDoctorActivities.contains { $0.queueStage == .completed }
        let allNonDoctorFinished = nonDoctorActivities.allSatisfy {
            $0.queueStage == .completed || $0.queueStage == .cancel
        }

        return hasCompletedNonDoctor && allNonDoctorFinished
    }

  
    private var canLeaveSession: Bool {
        simulatedStatus == .completed && requestedActivities.isEmpty && !canRecheckWithDoctor
    }

  
    private var canRefund: Bool {
        simulatedStatus == .waiting && activeActivity != nil
    }

    private var leaveButtonTitle: String {
        session.currentService == .appointment ? "Done" : "Leave Clinic"
    }

    private func getQueueNumber(for activity: Activity) -> Int {
        if let doctor = activity.selectedDoctor,
           let countString = doctor.activeQueueCount,
           let count = Int(countString) {
            return count + 1
        }
        return 14
    }

    private func getNowServingNumber(for queue: Int) -> Int {
        max(queue - 2, 1)
    }

    private func mapQueueStage(for status: StepStatus) -> QueueStages {
        switch status {
        case .pending, .waiting: return .wait
        case .next: return .next
        case .ready: return .ready
        case .inProgress: return .inProgress
        case .completed: return .completed
        }
    }

    private func updateActiveActivityQueue() {
        guard let activity = activeActivity else { return }
        if let index = session.activities.firstIndex(where: { $0.id == activity.id }) {
            session.activities[index].queueStage = mapQueueStage(for: simulatedStatus)
        }
    }

    private func resumeSimulation() {
        guard let activity = activeActivity else {
           
            simulatedStatus = .completed
            return
        }

        switch activity.queueStage {
        case .wait, .unknown:
            simulatedStatus = .waiting
            startQueueSimulation()
        case .next:
            simulatedStatus = .next
            scheduleRemainingStages(from: 1)
        case .ready:
            simulatedStatus = .ready
            scheduleRemainingStages(from: 2)
        case .inProgress:
            simulatedStatus = .inProgress
            scheduleRemainingStages(from: 3)
        case .completed:
            simulatedStatus = .completed
        case .cancel:
            break
        }
    }

    private func startQueueSimulation() {
        let steps: [StepStatus] = [.next, .ready, .inProgress, .completed]
        for (i, status) in steps.enumerated() {
            DispatchQueue.main.asyncAfter(deadline: .now() + Double((i + 1) * 5)) {
                simulatedStatus = status
                updateActiveActivityQueue()
            }
        }
    }

    private func scheduleRemainingStages(from startIndex: Int) {
        let steps: [StepStatus] = [.next, .ready, .inProgress, .completed]
        for i in startIndex..<steps.count {
            let delay = Double((i - startIndex + 1) * 5)
            let status = steps[i]
            DispatchQueue.main.asyncAfter(deadline: .now() + delay) {
                simulatedStatus = status
                updateActiveActivityQueue()
            }
        }
    }

    private func startRequestedActivity(_ activity: Activity) {
        guard simulatedStatus == .completed else { return }
        guard let tappedIndex = session.activities.firstIndex(where: { $0.id == activity.id }) else { return }

        let serviceType = session.activities[tappedIndex].service

        for index in session.activities.indices where session.activities[index].service == serviceType {
            session.activities[index].isSelected = false
        }

        session.activities[tappedIndex].isSelected = true
        session.activities[tappedIndex].queueStage = .unknown
        navigateToAppointment = true
    }

    private func rescheduleActivity(_ activity: Activity) {
        guard let index = session.activities.firstIndex(where: { $0.id == activity.id }) else { return }
        let removed = session.activities.remove(at: index)

        switch removed.service {
        case .lab:
            session.scheduledLab.append(removed)
        case .imaging:
            session.scheduledTest.append(removed)
        default:
            break
        }

        showRescheduleAlert = true
    }

    private func recheckWithDoctor() {
        guard let doctorActivity = session.activities.first(where: {
            $0.service == session.currentService && $0.selectedDoctor != nil
        }) else { return }

        if let index = session.activities.firstIndex(where: { $0.id == doctorActivity.id }) {
            session.activities[index].isSelected = true
            session.activities[index].queueStage = .wait
        }

        for index in session.activities.indices where session.activities[index].id != doctorActivity.id {
            session.activities[index].isSelected = false
        }

        simulatedStatus = .waiting
        session.hasRejoinedDoctor = true
        startQueueSimulation()
    }


    private func leaveCurrentSession() {
        let service = session.currentService
        session.activities.removeAll { $0.service == service }
        session.hasRejoinedDoctor = false
        session.currentAppointmentId = nil
    }

 
    private func performRefund() {
        let service = session.currentService
        if service == .appointment {
            session.refundCurrentAppointment()
            session.activities.removeAll { $0.service == .appointment }
            session.currentAppointmentId = nil
        } else {
            session.activities.removeAll { $0.service == .clinic }
            session.hasRejoinedDoctor = false
        }
    }

    private func debugActivities(_ title: String) {
        print("\n==============================")
        print("DEBUG: \(title)")
        print("Current Service: \(session.currentService)")
        print("Total Activities: \(session.activities.count)")
        for (index, act) in session.activities.enumerated() {
            print("[\(index)] \(act.testName ?? "Doctor") | stage: \(act.queueStage) | selected: \(act.isSelected)")
        }
        print("==============================\n")
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 24) {

                    if let activity = activeActivity {
                        let qNumber = getQueueNumber(for: activity)
                        let nowServing = getNowServingNumber(for: qNumber)

                        QueueBanner(
                            queueNumber: String(qNumber),
                            queueStage: mapQueueStage(for: simulatedStatus).toQueueStage(),
                            nowServingNumber: String(nowServing),
                            estimatedWait: "~15 minutes"
                        )
                        .padding(.horizontal, 20)
                    }

                    VStack(alignment: .leading, spacing: 24) {

                        if simulatedStatus == .completed {

                            if !completedActivities.isEmpty {
                                VStack(alignment: .leading, spacing: 16) {
                                    Text("Completed Services")
                                        .font(.system(size: 22, weight: .bold))
                                        .padding(.horizontal, 30)
                                        .padding(.top, 40)

                                    ForEach(completedActivities, id: \.id) { activity in
                                        activityCard(activity)
                                    }
                                }
                            }

                            if !requestedActivities.isEmpty {
                                VStack(alignment: .leading, spacing: 16) {
                                    Text("Requested Services")
                                        .font(.system(size: 22, weight: .bold))
                                        .padding(.horizontal, 30)

                                    ForEach(requestedActivities, id: \.id) { activity in
                                        activityCard(activity)
                                    }
                                }
                            }

                          
                            if canRecheckWithDoctor {
                                VStack(spacing: 16) {
                                    Button(action: { recheckWithDoctor() }) {
                                        Text("Recheck with Doctor")
                                            .font(.system(size: 16, weight: .medium))
                                            .foregroundColor(.white)
                                            .padding(.vertical, 12)
                                            .padding(.horizontal, 24)
                                            .background(Color(red: 0.28, green: 0.58, blue: 0.53))
                                            .cornerRadius(25)
                                    }
                                    .buttonStyle(.plain)
                                }
                                .frame(maxWidth: .infinity)
                                .padding(.horizontal, 30)
                            }

                           
                            if canLeaveSession {
                                VStack(spacing: 16) {
                                    Button(action: {
                                        leaveCurrentSession()
                                        navigateToServices = true
                                    }) {
                                        Text(leaveButtonTitle)
                                            .font(.system(size: 16, weight: .medium))
                                            .foregroundColor(Color(red: 0.28, green: 0.58, blue: 0.53))
                                            .padding(.vertical, 12)
                                            .padding(.horizontal, 24)
                                            .background(
                                                RoundedRectangle(cornerRadius: 25)
                                                    .stroke(Color(red: 0.28, green: 0.58, blue: 0.53), lineWidth: 1)
                                            )
                                    }
                                    .buttonStyle(.plain)
                                }
                                .frame(maxWidth: .infinity)
                                .padding(.horizontal, 30)
                                .multilineTextAlignment(.center)
                            }

                        } else {
                            if let activity = activeActivity {
                                activityCard(activity, showHeader: true)
                            }

                            
                            if canRefund {
                                Button(action: { showRefundConfirm = true }) {
                                    Text("Request Refund")
                                        .font(.system(size: 14, weight: .medium))
                                        .foregroundColor(.red)
                                        .padding(.vertical, 10)
                                        .padding(.horizontal, 20)
                                        .background(
                                            RoundedRectangle(cornerRadius: 20)
                                                .stroke(Color.red.opacity(0.6), lineWidth: 1)
                                        )
                                }
                                .buttonStyle(.plain)
                                .frame(maxWidth: .infinity)
                                .padding(.horizontal, 30)
                                .padding(.top, 8)
                            }
                        }
                    }
                    .padding(.horizontal, 20)
                }
                .padding(.top, 20)
                .padding(.bottom, 40)
            }
            .background(Color(white: 0.95))
            .alert("Rescheduled Successfully", isPresented: $showRescheduleAlert) {
                Button("OK", role: .cancel) { }
            } message: {
                Text("You can check your scheduled activities in the settings page.")
            }
            .onAppear {
 
                for index in session.activities.indices {
                    let act = session.activities[index]
                    if act.service == session.currentService &&
                       act.isSelected &&
                       act.queueStage == .unknown &&
                       act.testName != nil {
                        session.activities[index].isSelected = false
                    }
                }
                debugActivities("Queue Appeared")
                resumeSimulation()
            }
            
            .overlay {
                if showRefundConfirm {
                    CustomModal(isPresented: $showRefundConfirm) {
                        VStack(spacing: 20) {
                            Image(systemName: "arrow.uturn.backward.circle")
                                .font(.system(size: 48))
                                .foregroundColor(.red)

                            Text("Request Refund")
                                .font(.system(size: 18, weight: .bold))

                            Text("Are you sure you want to cancel your queue and request a refund? This action cannot be undone.")
                                .font(.system(size: 14))
                                .foregroundColor(.secondary)
                                .multilineTextAlignment(.center)

                            HStack(spacing: 12) {
                                Button(action: { showRefundConfirm = false }) {
                                    Text("Cancel")
                                        .font(.system(size: 15, weight: .medium))
                                        .foregroundColor(Color(red: 0.28, green: 0.58, blue: 0.53))
                                        .frame(maxWidth: .infinity)
                                        .padding(.vertical, 12)
                                        .background(
                                            RoundedRectangle(cornerRadius: 12)
                                                .stroke(Color(red: 0.28, green: 0.58, blue: 0.53), lineWidth: 1)
                                        )
                                }
                                .buttonStyle(.plain)

                                Button(action: {
                                    showRefundConfirm = false
                                    performRefund()
                                    showCashRefundQR = true
                                }) {
                                    Text("Confirm Refund")
                                        .font(.system(size: 15, weight: .semibold))
                                        .foregroundColor(.white)
                                        .frame(maxWidth: .infinity)
                                        .padding(.vertical, 12)
                                        .background(Color.red)
                                        .cornerRadius(12)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .padding()
                    }
                }
            }
            
            .overlay {
                if showCashRefundQR {
                    CashRefundSheet(isPresented: $showCashRefundQR) {
                        navigateToChanneling = true
                    }
                }
            }
            .navigationDestination(isPresented: $navigateToAppointment) {
                AppointmentStarterView()
            }
            .navigationDestination(isPresented: $navigateToServices) {
                ServicesView()
            }
            .navigationDestination(isPresented: $navigateToChanneling) {
                ChannelingHistoryView()
            }

        }.overlay(
            FloatingNav(
                mainIcon: "plus",
                items: [
                    FloatingNavItem(icon: "house.fill", label: "Home", destination: AnyView(ServicesView())),
                    FloatingNavItem(icon: "map.fill", label: "Map", destination: AnyView(Text("Map View"))),
                    FloatingNavItem(icon: "gearshape.fill", label: "Settings", destination: AnyView(ProfileView()))
                ]
            )
            .padding(.trailing, 30)
            .padding(.bottom, 30),
            alignment: .bottomTrailing
        )
    }

    @ViewBuilder
    private func activityCard(_ activity: Activity, showHeader: Bool = false) -> some View {
        VStack(alignment: .leading, spacing: 10) {

            if showHeader {
                Text("Service")
                    .font(.system(size: 22, weight: .bold))
                    .padding(.horizontal, 30)
                    .padding(.top, 40)
            }

            if let doctor = activity.selectedDoctor {
                InfoCard(data: doctor)
                    .padding(.horizontal, 30)
            } else {
                BloodTestCard(
                    image: TestDataset.imageName(for: activity.testName ?? ""),
                    title: activity.testName ?? "Test",
                    specialText: "",
                    detailLine1: "Location: \(activity.labStep?.location ?? activity.imagingStep?.location ?? "Main Lab - Level 2")",
                    detailLine2: "",
                    showExtraSection: true,
                    bottomTitleLeft: "Requirements",
                    listItems: activity.labStep?.requirements ?? activity.imagingStep?.requirements ?? ["N/A"],
                    bottomTitleRight: "Approximate Time",
                    bottomSubTextRight: activity.labStep?.estimatedWait ?? activity.imagingStep?.estimatedWait ?? "~30 min",
                    fee: {
                        if let price = activity.labStep?.price ?? activity.imagingStep?.price {
                            return "$\(Int(price))"
                        }
                        return "Free"
                    }(),
                    onButtonTap: {
                        startRequestedActivity(activity)
                    },
                    onRescheduleTap: {
                        rescheduleActivity(activity)
                    },
                    isActiveQueue: requestedActivities.contains(where: { $0.id == activity.id })
                )
                .padding(.horizontal, 20)
            }
        }
    }
}

#Preview {
    Queue()
}
