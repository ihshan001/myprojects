import SwiftUI

struct ServicesView: View {
    @EnvironmentObject var sessionManager: SessionManager
    @EnvironmentObject var session: SessionManagerV2

    private let services: [Service] = [
        Service(
            icon: "stethoscope",
            title: "Consultation Today",
            subtitle: "Complete clinic visit and treatment process.",
            background: Color(red: 247/255, green: 246/255, blue: 255/255),
            destination: .consultation
        ),
        Service(
            icon: "testtube.2",
            title: "Laboratory",
            subtitle: "Blood tests and diagnostic services.",
            background: Color(red: 232/255, green: 243/255, blue: 232/255),
            destination: .laboratory
        ),
        Service(
            icon: "hand.raised",
            title: "Imaging",
            subtitle: "X-ray and medical imaging services.",
            background: Color(red: 255/255, green: 249/255, blue: 232/255),
            destination: .imaging
        ),
        Service(
            icon: "pills",
            title: "Pharmacy",
            subtitle: "Collect prescribed medicines and supplies.",
            background: Color(red: 240/255, green: 242/255, blue: 246/255),
            destination: .pharmacy
        )
    ]

    @ViewBuilder
    private func destinationView(for service: Service) -> some View {
        switch service.destination {
        case .consultation:
            PatientDetailsFormView()
        case .laboratory:
            LabList()
        case .imaging:
            ImageList()
        case .pharmacy:
            PharmacyView()
        case .appointment:
            FindDoctorView()
        @unknown default:
            EmptyView()
        }
    }

    private func activitiesJSON() -> String {
        let grouped = Dictionary(grouping: session.activities) { $0.service.rawValue }

        var result: [String: [[String: Any]]] = [:]

        for (service, acts) in grouped {
            result[service] = acts.map { activity in
                var dict: [String: Any] = [
                    "id": activity.id.uuidString,
                    "stage": activity.stage.rawValue,
                    "queueStage": activity.queueStage.rawValue,
                    "isSelected": activity.isSelected,
                    "patientName": activity.patientName ?? "",
                    "patientAge": activity.patientAge ?? 0,
                    "patientGender": activity.patientGender ?? "",
                    "symptoms": activity.symptoms,
                    "testName": activity.testName ?? ""
                ]

                if let doc = activity.selectedDoctor {
                    dict["doctor"] = ["heading": doc.heading, "subheading": doc.subheading]
                } else {
                    dict["doctor"] = nil
                }

                return dict
            }
        }

        if let jsonData = try? JSONSerialization.data(withJSONObject: result, options: .prettyPrinted),
           let jsonString = String(data: jsonData, encoding: .utf8) {
            return jsonString
        }

        return "{}"
    }

    var body: some View {
        NavigationStack {
            // Active queue banner — also shows when there are pending tests waiting to be started
            let queuedActivity = session.activities.first(where: {
                switch $0.queueStage {
                case .wait, .next, .ready, .inProgress: return true
                default: return false
                }
            })
            let pendingTestActivity = session.activities.first(where: {
                $0.testName != nil && $0.queueStage == .unknown
            })
            let bannerActivity = queuedActivity ?? pendingTestActivity

            if let activity = bannerActivity {
                let isPendingTests = queuedActivity == nil
                let queueNumber = activity.queueNumber ?? 12
                let progress = min(max(queueNumber * 10, 0), 20)

                NavigationLink(
                    destination: Queue(),
                    label: {
                        ActiveQueueBanner(
                            title: isPendingTests
                                ? "Pending Tests"
                                : "Your Queue: \(activity.selectedDoctor?.heading ?? "Doctor")",
                            description: isPendingTests
                                ? "You have pending tests — tap to continue"
                                : "Patient: \(activity.patientName ?? "--"), Queue Number: \(queueNumber)",
                            queueNumber: isPendingTests ? "--" : "\(queueNumber)",
                            progress: isPendingTests ? 0 : progress,
                            onNavTap: {
                                session.currentService = activity.service
                            }
                        )
                        .padding(.bottom, 10)
                    }
                )
            }
            ZStack {
                Color(.white)
                    .ignoresSafeArea()

                ScrollView {
                    VStack(alignment: .leading, spacing: 24) {

                        HStack(alignment: .top) {
                            VStack(alignment: .leading, spacing: 8) {
                                Text("Find your desire")
                                    .font(.system(size: 26, weight: .bold))

                                Text("health solution")
                                    .font(.system(size: 26, weight: .bold))
                            }

                            Spacer()

                            HStack(spacing: 16) {

                                NavigationLink(destination: NotificationView()) {
                                    Image(systemName: "bell")
                                        .font(.system(size: 20))
                                        .foregroundColor(.black)
                                }

                                NavigationLink(destination: ProfileView()) {
                                    Circle()
                                        .fill(Color.gray.opacity(0.3))
                                        .frame(width: 36, height: 36)
                                        .overlay(
                                            Text("KL")
                                                .font(.caption)
                                                .foregroundColor(.black)
                                        )
                                }
                            }
                        }

                        LazyVGrid(
                            columns: [
                                GridItem(.flexible(), spacing: 16),
                                GridItem(.flexible(), spacing: 16)
                            ],
                            spacing: 16
                        ) {
                            ForEach(services) { service in
                                NavigationLink(destination: destinationView(for: service)) {
                                    ServiceCard(service: service)
                                        .frame(height: 190)
                                }
                            }
                        }

                        VStack(alignment: .leading, spacing: 8) {
                            Text("Doctor Channeling")
                                .font(.title2.weight(.bold))

                            Text("Reserve your appointment with a doctor at your preferred date and time.")
                                .font(.subheadline)
                                .foregroundColor(.secondary)
                                .fixedSize(horizontal: false, vertical: true)
                        }
                        .padding(.top, 8)

                        NavigationLink(destination: FindDoctorView()) {
                            CenteredServiceCard(
                                service: Service(
                                    icon: "calendar",
                                    title: "Doctor Appointment",
                                    subtitle: "Book and manage doctor appointments.",
                                    background: Color(red: 247/255, green: 246/255, blue: 255/255),
                                    destination: .appointment
                                )
                            )
                        }
                        .frame(maxWidth: .infinity)
                        .frame(height: 140)
                    }
                    .padding(.horizontal, 20)
                    .padding(.top, 4)
                    .padding(.bottom, 32)
                }

                FloatingNav(
                    mainIcon: "plus",
                    items: [
                        FloatingNavItem(icon: "house.fill", label: "Home", destination: AnyView(ServicesView())),
                        FloatingNavItem(icon: "map.fill", label: "Map", destination: AnyView(MapView())),
                        FloatingNavItem(icon: "gearshape.fill", label: "Settings", destination: AnyView(ProfileView()))
                    ]
                )
            }
        }.onAppear {
            print(activitiesJSON())
        }
    }
}

struct ServicesView_Previews_Fixed: PreviewProvider {
    static var previews: some View {
        ServicesView()
            .environmentObject(SessionManager())
    }
}
